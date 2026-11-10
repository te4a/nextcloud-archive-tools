<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OC\Files\Cache\Storage;
use OCA\ArchiveTools\AppInfo\Application;
use OCP\Files\Config\IUserMountCache;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/** Remove only virtual cache metadata; this backend has no user-file access. */
final class VirtualStorageCleanup {
    public const SCAN_LIMIT = 1000;
    public const DELETE_LIMIT = 20;
    public const CURSOR_KEY = 'orphanStorageCursor';

    public function __construct(
        private IDBConnection $db,
        private IUserMountCache $mountCache,
        private ILockingProvider $locks,
        private IAppConfig $config,
    ) {}

    private static function connectionId(string $identity): ?int {
        if (preg_match('/^archive_tools:([1-9][0-9]*):[a-f0-9]{24}$/D', $identity, $match) !== 1) { return null; }
        $id = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private function rows(int $after = 0, ?int $connection = null): array {
        $qb = $this->db->getQueryBuilder();
        $prefix = 'archive_tools:' . ($connection === null ? '' : $connection . ':');
        return $qb->select('id', 'numeric_id')->from('storages')
            ->where($qb->expr()->like('id', $qb->createNamedParameter($this->db->escapeLikeParameter($prefix) . '%')))
            ->andWhere($qb->expr()->gt('numeric_id', $qb->createNamedParameter($after)))
            ->orderBy('numeric_id')->setMaxResults(self::SCAN_LIMIT)->executeQuery()->fetchAllAssociative();
    }

    public function countAll(): int {
        $count = 0; $after = 0;
        do {
            $rows = $this->rows($after);
            foreach ($rows as $row) {
                $after = (int)$row['numeric_id'];
                if (self::connectionId((string)$row['id']) !== null) { ++$count; }
            }
        } while (count($rows) === self::SCAN_LIMIT);
        return $count;
    }

    /** Caller holds the connection's exclusive lock and checked its leases. */
    public function removeConnection(int $connection): int {
        if ($connection < 1) { throw new \InvalidArgumentException('Invalid archive connection identity.'); }
        $removed = 0; $after = 0;
        do {
            $rows = $this->rows($after, $connection);
            foreach ($rows as $row) {
                $after = (int)$row['numeric_id'];
                if (self::connectionId((string)$row['id']) === $connection && $this->remove($row)) { ++$removed; }
            }
        } while (count($rows) === self::SCAN_LIMIT);
        return $removed;
    }

    /** Caller holds the exclusive lifecycle barrier and all live connection locks. */
    public function removeAll(): int {
        $removed = 0; $after = 0;
        do {
            $rows = $this->rows($after);
            foreach ($rows as $row) {
                $after = (int)$row['numeric_id'];
                if (self::connectionId((string)$row['id']) !== null && $this->remove($row)) { ++$removed; }
            }
        } while (count($rows) === self::SCAN_LIMIT);
        return $removed;
    }

    /** Caller serializes with creation through LifecycleService::write(). */
    public function reapOrphans(): array {
        $cursor = max(0, $this->config->getValueInt(Application::APP_ID, self::CURSOR_KEY, 0));
        $result = ['scanned' => 0, 'removed' => 0, 'busy' => 0, 'skipped' => 0,
            'cursor' => $cursor, 'wrapped' => false, 'scanLimitReached' => false, 'deletionLimitReached' => false];
        $rows = $this->rows($cursor);
        if ($rows === [] && $cursor > 0) {
            $cursor = 0; $rows = $this->rows(); $result['wrapped'] = true;
        }
        foreach ($rows as $row) {
            $cursor = (int)$row['numeric_id']; ++$result['scanned'];
            $id = self::connectionId((string)$row['id']);
            if ($id === null) { ++$result['skipped']; continue; }
            $key = 'archive_tools/connection/' . $id;
            try { $this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE); }
            catch (LockedException) { ++$result['busy']; continue; }
            try {
                $qb = $this->db->getQueryBuilder();
                $live = $qb->select('id')->from('archive_tools_mounts')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeQuery()->fetchOne();
                $qb = $this->db->getQueryBuilder();
                $leased = (int)$qb->select($qb->func()->count('*'))->from('archive_tools_leases')
                    ->where($qb->expr()->eq('mount_id', $qb->createNamedParameter($id)))
                    ->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter(time())))->executeQuery()->fetchOne();
                if ($live !== false || $leased > 0) { ++$result['skipped']; continue; }
                if ($this->remove($row)) { ++$result['removed']; }
            } finally { $this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE); }
            if ($result['removed'] >= self::DELETE_LIMIT) { $result['deletionLimitReached'] = true; break; }
        }
        $result['scanLimitReached'] = count($rows) === self::SCAN_LIMIT && $result['scanned'] === self::SCAN_LIMIT;
        // Keep the last processed identity, including skipped rows. The next pass
        // eventually wraps rather than repeatedly scanning an early live prefix.
        $result['cursor'] = $cursor;
        $this->config->setValueInt(Application::APP_ID, self::CURSOR_KEY, $cursor);
        return $result;
    }

    private function remove(array $row): bool {
        $id = (string)$row['id']; $numeric = (int)$row['numeric_id'];
        if (self::connectionId($id) === null || $numeric < 1) { return false; }
        $qb = $this->db->getQueryBuilder();
        $current = $qb->select('numeric_id')->from('storages')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeQuery()->fetchOne();
        if ($current === false) { return false; }
        if ((int)$current !== $numeric) { throw new \RuntimeException('The archive cache identity changed during cleanup.'); }
        $storage = new MetadataOnlyStorage(['identity' => $id]);
        $cache = $storage->getCache();
        if ($cache->getNumericStorageId() !== $numeric) { throw new \RuntimeException('The archive cache identity changed during cleanup.'); }
        $cache->remove('');
        $previousPaths = null;
        do {
            $qb = $this->db->getQueryBuilder();
            $paths = $qb->select('path')->from('filecache')->where($qb->expr()->eq('storage', $qb->createNamedParameter($numeric)))
                ->orderBy('fileid')->setMaxResults(200)->executeQuery()->fetchFirstColumn();
            if ($paths !== [] && $paths === $previousPaths) { throw new \RuntimeException('The archive cache metadata could not be removed.'); }
            foreach ($paths as $path) { $cache->remove((string)$path); }
            $previousPaths = $paths;
        } while ($paths !== []);
        $this->mountCache->remoteStorageMounts($numeric);
        $qb = $this->db->getQueryBuilder();
        $removed = $qb->delete('storages')->where($qb->expr()->eq('numeric_id', $qb->createNamedParameter($numeric)))
            ->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
        Storage::getGlobalCache()->clearCache();
        return $removed === 1;
    }
}
