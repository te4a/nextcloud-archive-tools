<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;

/** Fresh durable state plus a shared/exclusive barrier around app operations. */
final class LifecycleService {
    private const LOCK_KEY = 'archive_tools/lifecycle';
    private bool $tableKnown = false;

    public function __construct(private IDBConnection $db, private ILockingProvider $locks) {}

    public function state(): ?string {
        // Avoid a missing-table SELECT aborting an enclosing PostgreSQL
        // upgrade transaction when core assembles the filesystem early.
        if (!$this->tableKnown) {
            if (!$this->db->tableExists('archive_tools_state')) { return null; }
            $this->tableKnown = true;
        }
        $qb = $this->db->getQueryBuilder();
        $value = $qb->select('state')->from('archive_tools_state')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1)))
            ->executeQuery()->fetchOne();
        return $value === false ? null : (string)$value;
    }

    public function enter(): LifecycleLease {
        $this->locks->acquireLock(self::LOCK_KEY, ILockingProvider::LOCK_SHARED);
        try {
            $this->assertReady();
            return new LifecycleLease($this->locks, self::LOCK_KEY);
        } catch (\Throwable $error) {
            $this->locks->releaseLock(self::LOCK_KEY, ILockingProvider::LOCK_SHARED);
            throw $error;
        }
    }

    public function assertReady(): void {
        $state = $this->state();
        if ($state === null || !preg_match('/^ready:[a-f0-9]{32}$/D', $state)) {
            throw new \RuntimeException('Archive Tools is not ready. Complete its upgrade or reinstall it before continuing.');
        }
    }

    /**
     * Serialize persisted app writes with the cleanup tombstone even if a
     * cache-based lock expires. Updating to a fresh nonce takes a database row
     * lock and returns one changed row on PostgreSQL, SQLite and MySQL alike.
     */
    public function write(callable $operation): mixed {
        $lease = $this->enter();
        try {
            $this->db->beginTransaction();
            try {
                $qb = $this->db->getQueryBuilder();
                $changed = $qb->update('archive_tools_state')
                    ->set('state', $qb->createNamedParameter('ready:' . bin2hex(random_bytes(16))))
                    ->set('updated_at', $qb->createNamedParameter(time()))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter(1)))
                    ->andWhere($qb->expr()->like('state', $qb->createNamedParameter('ready:%')))
                    ->executeStatement();
                if ($changed !== 1) { $this->assertReady(); throw new \RuntimeException('Archive Tools is unavailable during cleanup.'); }
                $result = $operation();
                $this->db->commit();
                return $result;
            } catch (\Throwable $error) {
                $this->db->rollBack();
                throw $error;
            }
        } finally { $lease->close(); }
    }

    public function exclusive(callable $operation): mixed {
        $this->locks->acquireLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
        try { return $operation(); }
        finally { $this->locks->releaseLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE); }
    }

    /** Run only from the explicit install/upgrade repair step. */
    public function initialize(bool $freshInstall = false): void {
        $this->exclusive(function () use ($freshInstall): void {
            $state = $this->state();
            if ($state !== null && preg_match('/^ready:[a-f0-9]{32}$/D', $state)) { return; }
            // Reopen a purged tombstone only from the explicit install hook.
            // Ordinary boot, upgrade and maintenance repair never reopen it.
            if ($state !== null && !($state === 'purged' && $freshInstall)) {
                throw new \RuntimeException('Archive Tools cleanup state requires administrator review before repair.');
            }
            $qb = $this->db->getQueryBuilder();
            $values = ['state' => $qb->createNamedParameter('ready:' . bin2hex(random_bytes(16))),
                'updated_at' => $qb->createNamedParameter(time())];
            if ($state === null) {
                $qb->insert('archive_tools_state')->values(['id' => $qb->createNamedParameter(1)] + $values)->executeStatement();
            } else {
                $qb->update('archive_tools_state');
                foreach ($values as $key => $value) { $qb->set($key, $value); }
                $qb->where($qb->expr()->eq('id', $qb->createNamedParameter(1)))->executeStatement();
            }
        });
    }
}
