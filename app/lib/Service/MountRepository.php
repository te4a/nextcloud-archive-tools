<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;

final class MountRepository {
    private array $touched = [];
    private array $exclusiveLocks = [];
    private array $activeLeases = [];
    private const LEASE_SECONDS = 300;

    public function __construct(private IDBConnection $db, private ILockingProvider $locks, private VirtualStorageCleanup $virtualStorageCleanup) {}

    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        // Projections and lifecycle scans need identity, never every archive's
        // potentially large index or encrypted credential at once.
        return $qb->select('id', 'source_id', 'parent_id', 'mount_name', 'created_by',
            'pinned', 'last_access', 'source_etag')
            ->from('archive_tools_mounts')->orderBy('id')->executeQuery()->fetchAllAssociative();
    }

    public function find(int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $row = $qb->select('*')->from('archive_tools_mounts')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    public function create(array $values): int {
        $qb = $this->db->getQueryBuilder();
        $parameters = [];
        foreach ($values as $key => $value) {
            $parameters[$key] = $qb->createNamedParameter($value);
        }
        $qb->insert('archive_tools_mounts')->values($parameters)->executeStatement();
        return (int)$qb->getLastInsertId();
    }

    public function touch(int $id, bool $force = false): void {
        if (!$force && isset($this->touched[$id])) {
            return;
        }
        $this->touched[$id] = true;
        $qb = $this->db->getQueryBuilder();
        $qb->update('archive_tools_mounts')->set('last_access', $qb->createNamedParameter(time()))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
    }

    public function setPinned(int $id, bool $pinned): void {
        $this->withExclusive($id, function () use ($id, $pinned): void {
            $qb = $this->db->getQueryBuilder();
            $qb->update('archive_tools_mounts')->set('pinned', $qb->createNamedParameter((int)$pinned))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
        });
    }

    public function delete(int $id): void {
        $this->withExclusive($id, function () use ($id): void {
            if ($this->hasActiveLeases($id)) {
                throw new \RuntimeException('The archive is currently in use. Try again when the operation has finished.');
            }
            $this->virtualStorageCleanup->removeConnection($id);
            $qb = $this->db->getQueryBuilder();
            $qb->delete('archive_tools_mounts')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
            $qb = $this->db->getQueryBuilder();
            $qb->delete('archive_tools_leases')->where($qb->expr()->eq('mount_id', $qb->createNamedParameter($id)))->executeStatement();
        });
    }

    /** Connection identity locks coordinate readers, explicit deletion and cron. */
    public function withExclusive(int $id, callable $operation): mixed {
        if (isset($this->exclusiveLocks[$id])) {
            return $operation();
        }
        $this->locks->acquireLock($this->lockKey($id), ILockingProvider::LOCK_EXCLUSIVE);
        $this->exclusiveLocks[$id] = true;
        try {
            return $operation();
        } finally {
            unset($this->exclusiveLocks[$id]);
            $this->locks->releaseLock($this->lockKey($id), ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    public function withShared(int $id, callable $operation): mixed {
        $this->locks->acquireLock($this->lockKey($id), ILockingProvider::LOCK_SHARED);
        try {
            if ($this->find($id) === null) {
                throw new \RuntimeException('The archive connection is no longer available.');
            }
            return $operation();
        } finally {
            $this->locks->releaseLock($this->lockKey($id), ILockingProvider::LOCK_SHARED);
        }
    }

    /** @return array{token:string,id:int,activity:bool} */
    public function beginReadLease(int $id, bool $activity = true): array {
        $this->locks->acquireLock($this->lockKey($id), ILockingProvider::LOCK_SHARED);
        try {
            if ($this->find($id) === null) {
                throw new \RuntimeException('The archive connection is no longer available.');
            }
            $token = bin2hex(random_bytes(24));
            $qb = $this->db->getQueryBuilder();
            $qb->insert('archive_tools_leases')->values([
                'token' => $qb->createNamedParameter($token),
                'mount_id' => $qb->createNamedParameter($id),
                'expires_at' => $qb->createNamedParameter(time() + self::LEASE_SECONDS),
            ])->executeStatement();
            $lease = ['token' => $token, 'id' => $id, 'activity' => $activity];
            $this->activeLeases[$token] = time();
            if ($activity) {
                $this->touch($id, true);
            }
            return $lease;
        } catch (\Throwable $error) {
            if (isset($token)) {
                unset($this->activeLeases[$token]);
                try {
                    $qb = $this->db->getQueryBuilder();
                    $qb->delete('archive_tools_leases')->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))->executeStatement();
                } catch (\Throwable) {
                    // A failed database remains fail-closed until lease expiry.
                }
            }
            $this->locks->releaseLock($this->lockKey($id), ILockingProvider::LOCK_SHARED);
            throw $error;
        }
    }

    public function renewReadLease(array $lease): void {
        $token = $lease['token'];
        if (!isset($this->activeLeases[$token]) || time() - $this->activeLeases[$token] < 30) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update('archive_tools_leases')->set('expires_at', $qb->createNamedParameter(time() + self::LEASE_SECONDS))
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))->executeStatement();
        $this->activeLeases[$token] = time();
        if ($lease['activity']) {
            $this->touch((int)$lease['id'], true);
        }
    }

    public function endReadLease(array $lease): void {
        $token = $lease['token'];
        if (!isset($this->activeLeases[$token])) {
            return;
        }
        unset($this->activeLeases[$token]);
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('archive_tools_leases')->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))->executeStatement();
            if ($lease['activity']) {
                $this->touch((int)$lease['id'], true);
            }
        } finally {
            $this->locks->releaseLock($this->lockKey((int)$lease['id']), ILockingProvider::LOCK_SHARED);
        }
    }

    public function beginSourceActivity(int $sourceId): array {
        $leases = [];
        try {
            foreach ($this->findAll() as $mount) {
                if ((int)$mount['source_id'] !== $sourceId) {
                    continue;
                }
                try {
                    $leases[] = $this->beginReadLease((int)$mount['id']);
                } catch (\RuntimeException $error) {
                    if ($this->find((int)$mount['id']) !== null) {
                        throw $error;
                    }
                }
            }
            return $leases;
        } catch (\Throwable $error) {
            $this->endSourceActivity($leases);
            throw $error;
        }
    }

    public function renewSourceActivity(array $leases): void {
        foreach ($leases as $lease) {
            $this->renewReadLease($lease);
        }
    }

    public function endSourceActivity(array $leases): void {
        $failure = null;
        foreach ($leases as $lease) {
            try {
                $this->endReadLease($lease);
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function hasActiveLeases(int $id, ?int $now = null): bool {
        $qb = $this->db->getQueryBuilder();
        return (int)$qb->select($qb->func()->count('*'))->from('archive_tools_leases')
            ->where($qb->expr()->eq('mount_id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now ?? time())))
            ->executeQuery()->fetchOne() > 0;
    }

    public function removeExpiredLeases(?int $now = null): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('archive_tools_leases')->where($qb->expr()->lte('expires_at', $qb->createNamedParameter($now ?? time())))->executeStatement();
    }

    /** Global cache evidence is used only for confirmed deletion, never to grant access. */
    public function sourceCacheInfo(int $sourceId): ?array {
        $qb = $this->db->getQueryBuilder();
        $row = $qb->select('fileid', 'path', 'etag', 'storage')->from('filecache')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($sourceId)))->executeQuery()->fetchAssociative();
        if ($row === false) {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        $row['storage_string_id'] = $qb->select('id')->from('storages')
            ->where($qb->expr()->eq('numeric_id', $qb->createNamedParameter((int)$row['storage'])))->executeQuery()->fetchOne();
        return $row;
    }

    private function lockKey(int $id): string {
        return 'archive_tools/connection/' . $id;
    }
}
