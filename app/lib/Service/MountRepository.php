<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OCA\ArchiveTools\Storage\ArchiveStorage;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Cache\IFileAccess;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;

final class MountRepository {
    public const PREVIEW_IDLE_MINUTES = 30;
    private array $touched = [];
    private array $exclusiveLocks = [];
    private array $activeLeases = [];
    private bool $cacheTableKnown = false;
    private const LEASE_SECONDS = 300;

    public function __construct(
        private IDBConnection $db,
        private ILockingProvider $locks,
        private VirtualStorageCleanup $virtualStorageCleanup,
        private IFileAccess $fileCache,
    ) {}

    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        // Projections and lifecycle scans need identity, never every archive's
        // potentially large index or encrypted credential at once.
        $qb->select('m.id', 'm.source_id', 'm.parent_id', 'm.mount_name', 'm.created_by',
            'm.pinned', 'm.last_access', 'm.source_etag', 'm.preview_depth')
            ->from('archive_tools_mounts', 'm')->orderBy('m.id');
        $this->joinCacheRoute($qb);
        return $qb->executeQuery()->fetchAllAssociative();
    }

    public function find(int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.*')->from('archive_tools_mounts', 'm')
            ->where($qb->expr()->eq('m.id', $qb->createNamedParameter($id)));
        $this->joinCacheRoute($qb);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    private function exists(int $id): bool {
        $qb = $this->db->getQueryBuilder();
        return $qb->select('id')->from('archive_tools_mounts')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeQuery()->fetchOne() !== false;
    }

    public function findPreview(string $owner, int $sourceId, string $name): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('archive_tools_mounts')
            ->where($qb->expr()->eq('created_by', $qb->createNamedParameter($owner)))
            ->andWhere($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId)))
            ->andWhere($qb->expr()->eq('mount_name', $qb->createNamedParameter($name)))
            ->andWhere($qb->expr()->gt('preview_depth', $qb->createNamedParameter(0)));
        $id = $qb->executeQuery()->fetchOne();
        return $id === false ? null : (int)$id;
    }

    private function joinCacheRoute(IQueryBuilder $qb): void {
        // Files can initialize while Nextcloud is still applying the upgrade.
        if (!$this->cacheTableKnown) {
            $this->cacheTableKnown = $this->db->tableExists('archive_tools_cache');
        }
        if ($this->cacheTableKnown) {
            $qb->leftJoin('m', 'archive_tools_cache', 'c', $qb->expr()->eq('m.id', 'c.mount_id'))
                ->addSelect('c.cache_identity', 'c.cache_path', 'c.cache_numeric_id');
        }
    }

    public function create(array $values): int {
        $transaction = !$this->db->inTransaction();
        if ($transaction) {
            $this->db->beginTransaction();
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $parameters = [];
            foreach ($values as $key => $value) {
                $parameters[$key] = $qb->createNamedParameter($value);
            }
            $qb->insert('archive_tools_mounts')->values($parameters)->executeStatement();
            $id = (int)$qb->getLastInsertId();
            $this->reserveCache($id, ArchiveStorage::STORAGE_ID, ArchiveStorage::branch($id));
            if ($transaction) {
                $this->db->commit();
            }
            return $id;
        } catch (\Throwable $error) {
            if ($transaction) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** Called before materializing a new cache or when adopting a known old connection. */
    public function reserveCache(int $id, string $identity, string $path, ?int $numericId = null): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('archive_tools_cache')->values([
            'mount_id' => $qb->createNamedParameter($id),
            'cache_identity' => $qb->createNamedParameter($identity),
            'cache_path' => $qb->createNamedParameter($path),
            'cache_numeric_id' => $qb->createNamedParameter($numericId),
        ])->executeStatement();
    }

    public function rememberCacheStorage(int $id, int $numeric): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('archive_tools_cache')->set('cache_numeric_id', $qb->createNamedParameter($numeric))
            ->where($qb->expr()->eq('mount_id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('cache_path', $qb->createNamedParameter('')))->executeStatement();
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
            $transaction = !$this->db->inTransaction();
            if ($transaction) {
                $this->db->beginTransaction();
            }
            try {
                $this->virtualStorageCleanup->removeConnection($id);
                $qb = $this->db->getQueryBuilder();
                $qb->delete('archive_tools_mounts')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
                $qb = $this->db->getQueryBuilder();
                $qb->delete('archive_tools_leases')->where($qb->expr()->eq('mount_id', $qb->createNamedParameter($id)))->executeStatement();
                if ($transaction) {
                    $this->db->commit();
                }
            } catch (\Throwable $error) {
                if ($transaction) {
                    $this->db->rollBack();
                }
                throw $error;
            }
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
            if (!$this->exists($id)) {
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
            if (!$this->exists($id)) {
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

    /** A trashed source retains its file ID so restoration can revive the connection. */
    public function isSourceMissing(int $sourceId): bool {
        return $this->fileCache->getByFileId($sourceId) === null;
    }

    private function lockKey(int $id): string {
        return 'archive_tools/connection/' . $id;
    }
}
