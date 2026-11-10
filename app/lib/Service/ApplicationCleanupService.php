<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OCA\ArchiveTools\AppInfo\Application;
use OCA\ArchiveTools\BackgroundJob\CleanupJob;
use OCA\ArchiveTools\BackgroundJob\ExtractJob;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/** App-owned metadata cleanup. Source/output file IDs are never dereferenced. */
final class ApplicationCleanupService {
    public function __construct(
        private IDBConnection $db,
        private LifecycleService $lifecycle,
        private IAppManager $apps,
        private IAppConfig $config,
        private IUserConfig $users,
        private IJobList $queue,
        private IManager $notifications,
        private VirtualStorageCleanup $virtualStorageCleanup,
        private ILockingProvider $locks,
        private TemporaryResourceCleanup $temporary,
        private LoggerInterface $logger,
    ) {}

    private function count(string $table, ?callable $filter = null): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))->from($table);
        if ($filter !== null) { $filter($qb); }
        return (int)$qb->executeQuery()->fetchOne();
    }

    /** Counts only: never return archive names, paths, owners or credentials. */
    public function report(): array {
        return [
            'lifecycleState' => $this->lifecycle->state(),
            'connections' => $this->count('archive_tools_mounts'),
            'protectedConnections' => $this->count('archive_tools_mounts', fn ($q) => $q->where($q->expr()->isNotNull('password'))),
            'tasks' => $this->count('archive_tools_jobs'),
            'protectedTasks' => $this->count('archive_tools_jobs', fn ($q) => $q->where($q->expr()->isNotNull('secret'))),
            'runningTasks' => $this->count('archive_tools_jobs', fn ($q) => $q->where($q->expr()->eq('state', $q->createNamedParameter('running')))),
            'activeStreams' => $this->count('archive_tools_leases', fn ($q) => $q->where($q->expr()->gt('expires_at', $q->createNamedParameter(time())))),
            'virtualStorages' => $this->virtualStorageCleanup->countAll(),
            'temporaryResources' => $this->temporary->inspect(),
        ];
    }

    /**
     * Explicit administrative purge only; never register this on disable.
     * The lifecycle barrier and durable state row serialize in-flight writers.
     */
    public function purge(): array {
        return $this->lifecycle->exclusive(function (): array {
            if ($this->lifecycle->state() === 'purged') { return ['alreadyPurged' => true]; }
            $this->lifecycle->assertReady();
            $held = [];
            $committed = false;
            $this->db->beginTransaction();
            try {
                $qb = $this->db->getQueryBuilder();
                $changed = $qb->update('archive_tools_state')->set('state', $qb->createNamedParameter('purging'))
                    ->set('updated_at', $qb->createNamedParameter(time()))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter(1)))
                    ->andWhere($qb->expr()->like('state', $qb->createNamedParameter('ready:%')))->executeStatement();
                if ($changed !== 1) { throw new \RuntimeException('Archive Tools cleanup state is unavailable. No data was removed.'); }
                $before = $this->report();
                // Durable evidence also protects a worker whose cache-based
                // lock expired. Recovery must finish before an explicit purge.
                if ($before['runningTasks'] > 0 || $before['activeStreams'] > 0) {
                    throw new \RuntimeException('Archive Tools is in use. Wait for running tasks and downloads to finish before purging.');
                }
                $qb = $this->db->getQueryBuilder();
                $ids = $qb->select('id')->from('archive_tools_mounts')->orderBy('id')->executeQuery()->fetchFirstColumn();
                foreach ($ids as $id) {
                    $key = 'archive_tools/connection/' . (int)$id;
                    $this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
                    $held[] = $key;
                }
                // The public manager preserves the ordinary disable semantics.
                // Destructive work exists only in this explicitly invoked path.
                $this->apps->disableApp(Application::APP_ID);
                $this->queue->remove(ExtractJob::class);
                $this->queue->remove(CleanupJob::class);
                $removedStorages = $this->virtualStorageCleanup->removeAll();
                foreach (['archive_tools_leases', 'archive_tools_jobs', 'archive_tools_mounts'] as $table) {
                    $this->db->getQueryBuilder()->delete($table)->executeStatement();
                }
                $this->notifications->markProcessed($this->notifications->createNotification()->setApp(Application::APP_ID));
                $this->users->deleteApp(Application::APP_ID);
                $this->config->deleteApp(Application::APP_ID);
                // Let the public manager recreate its own enabled=no value.
                // A typed string here conflicts with core's legacy mixed key
                // on the next explicit reinstall/enable operation.
                $this->apps->disableApp(Application::APP_ID);
                $qb = $this->db->getQueryBuilder();
                $qb->update('archive_tools_state')->set('state', $qb->createNamedParameter('purged'))
                    ->set('updated_at', $qb->createNamedParameter(time()))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter(1)))->executeStatement();
                $this->db->commit();
                $committed = true;
                // Expired temporary aliases/lists are caches, not user files.
                // Fresh or future-dated orphans remain under the one-hour rule.
                try { $temporary = $this->temporary->cleanup(); }
                catch (\Throwable $error) {
                    $temporary = $before['temporaryResources'] + ['cleanupFailed' => true];
                    $this->logger->warning('Expired archive temporary resources could not be removed.', [
                        'app' => Application::APP_ID, 'exceptionClass' => $error::class,
                    ]);
                }
                return ['alreadyPurged' => false, 'connections' => $before['connections'],
                    'tasks' => $before['tasks'], 'virtualStorages' => $removedStorages, 'temporaryResources' => $temporary];
            } catch (\Throwable $error) {
                if (!$committed) { $this->db->rollBack(); }
                throw $error;
            } finally {
                foreach (array_reverse($held) as $key) { $this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE); }
            }
        });
    }

}
