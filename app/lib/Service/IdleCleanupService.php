<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** Native cron removes connections, never source archives or extracted data. */
final class IdleCleanupService {
    public function __construct(
        private MountRepository $mounts,
        private SettingsService $settings,
        private MountInvalidator $invalidator,
        private LoggerInterface $logger,
        private JobRepository $jobs,
        private LifecycleService $lifecycle,
        private TemporaryResourceCleanup $temporary,
        private VirtualStorageCleanup $virtualStorageCleanup,
    ) {}

    /** @return array{idle:int,missing:int,busy:int,removed:array<int>,jobsDeleted:int,jobSecretsCleared:int,inputsCleaned:int,nameListsCleaned:int} */
    public function cleanup(?int $now = null): array {
        return $this->lifecycle->write(fn (): array => $this->cleanupReady($now));
    }

    private function cleanupReady(?int $now): array {
        $now ??= time();
        $settings = $this->settings->getSettings();
        $minutes = $settings['idleTimeoutMinutes'];
        $result = ['idle' => 0, 'missing' => 0, 'busy' => 0, 'removed' => [], 'jobsDeleted' => 0, 'jobSecretsCleared' => 0, 'inputsCleaned' => 0, 'nameListsCleaned' => 0];
        try {
            $temporary = $this->temporary->cleanup();
            $result['inputsCleaned'] = $temporary['removedInputs'];
            $result['nameListsCleaned'] = $temporary['removedNameLists'];
        }
        catch (\Throwable $error) {
            $this->logger->warning('Stale archive temporary resources could not be cleaned up.', [
                'app' => 'archive_tools', 'exceptionClass' => $error::class,
            ]);
        }
        try {
            $history = $this->jobs->cleanupHistory($settings['jobsRetentionDays'], $now);
            $result['jobsDeleted'] = $history['deleted'];
            $result['jobSecretsCleared'] = $history['secretsCleared'];
        } catch (\Throwable $error) {
            $this->logger->warning('Archive task history could not be cleaned up.', [
                'app' => 'archive_tools', 'exceptionClass' => $error::class,
            ]);
        }
        $this->mounts->removeExpiredLeases($now);
        foreach ($this->mounts->findAll() as $candidate) {
            $id = (int)$candidate['id'];
            try {
                $removed = $this->mounts->withExclusive($id, function () use ($id, $minutes, $now): ?array {
                    $mount = $this->mounts->find($id);
                    if ($mount === null || $this->mounts->hasActiveLeases($id, $now)) {
                        return null;
                    }
                    $source = $this->mounts->sourceCacheInfo((int)$mount['source_id']);
                    // A creator losing a share is not evidence of deletion:
                    // other participants may still use the same connection.
                    // Only global filecache removal or a home trash path is
                    // conclusive. Temporary remote-storage/ACL failures are not.
                    $missing = $source === null || (
                        str_starts_with((string)$source['storage_string_id'], 'home::')
                        && str_starts_with((string)$source['path'], 'files_trashbin/')
                    );
                    $idle = $minutes > 0 && !(bool)$mount['pinned']
                        && (int)$mount['last_access'] <= $now - $minutes * 60;
                    if (!$missing && !$idle) {
                        return null;
                    }
                    $this->mounts->delete($id);
                    return ['parentId' => (int)$mount['parent_id'], 'reason' => $missing ? 'missing' : 'idle'];
                });
                if ($removed !== null) {
                    ++$result[$removed['reason']];
                    $result['removed'][] = $id;
                    $this->invalidator->invalidate($removed['parentId']);
                }
            } catch (LockedException) {
                ++$result['busy'];
            } catch (\Throwable $error) {
                // No row, paths, password or exception arguments are logged.
                $this->logger->warning('An archive connection could not be cleaned up.', [
                    'app' => 'archive_tools', 'connectionId' => $id,
                    'exceptionClass' => $error::class,
                ]);
            }
        }
        try {
            $result['virtualStorageCleanup'] = $this->virtualStorageCleanup->reapOrphans();
        } catch (\Throwable $error) {
            $this->logger->warning('Orphaned archive cache metadata could not be cleaned up.', [
                'app' => 'archive_tools', 'exceptionClass' => $error::class,
            ]);
        }
        return $result;
    }
}
