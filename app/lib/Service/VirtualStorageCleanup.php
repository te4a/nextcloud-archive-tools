<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OCA\ArchiveTools\AppInfo\Application;
use OCA\ArchiveTools\Mount\MountProvider;
use OCA\ArchiveTools\Storage\ArchiveStorage;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Cache\ICache;
use OCP\Files\Cache\ICacheEntry;
use OCP\Files\Cache\IFileAccess;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\FileInfo;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchOrder;
use OCP\Files\Search\ISearchQuery;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/** The app ledger identifies cache locations independently of their contents. */
final class VirtualStorageCleanup {
    public const SCAN_LIMIT = 1000;
    public const DELETE_LIMIT = 20;
    public const CURSOR_KEY = 'orphanArchiveCacheMountCursor';
    private const REMOVE_BATCH = 200;

    public function __construct(
        private IDBConnection $db,
        private IUserMountCache $mountCache,
        private IFileAccess $fileAccess,
        private ILockingProvider $locks,
        private IAppConfig $config,
    ) {}

    /** Number of tracked locations, including interrupted rootless branches. */
    public function countAll(): int {
        $query = $this->db->getQueryBuilder();
        return (int)$query->select($query->func()->count('*'))->from('archive_tools_cache')->executeQuery()->fetchOne();
    }

    private function locations(bool $orphaned = false): IQueryBuilder {
        $query = $this->db->getQueryBuilder();
        $query->select('c.mount_id', 'c.cache_identity', 'c.cache_path', 'c.cache_numeric_id')->from('archive_tools_cache', 'c');
        if ($orphaned) {
            $query->leftJoin('c', 'archive_tools_mounts', 'm', $query->expr()->eq('m.id', 'c.mount_id'))
                ->leftJoin('c', 'archive_tools_leases', 'l', $query->expr()->andX(
                    $query->expr()->eq('l.mount_id', 'c.mount_id'),
                    $query->expr()->gt('l.expires_at', $query->createNamedParameter(time(), IQueryBuilder::PARAM_INT)),
                ))
                ->where($query->expr()->isNull('m.id'))
                ->andWhere($query->expr()->isNull('l.mount_id'));
        }
        return $query;
    }

    /** Caller holds the connection's exclusive lock and transaction. */
    public function removeConnection(int $connection): int {
        if ($connection < 1) {
            throw new \InvalidArgumentException('Invalid archive connection identity.');
        }
        $query = $this->locations();
        $location = $query->where($query->expr()->eq('c.mount_id', $query->createNamedParameter($connection, IQueryBuilder::PARAM_INT)))
            ->executeQuery()->fetchAssociative();
        if ($location === false) {
            return 0;
        }
        $this->removeLocation($location);
        return 1;
    }

    /** Caller holds the exclusive lifecycle barrier and all live connection locks. */
    public function removeAll(): int {
        $removed = 0;
        $cursor = 0;
        do {
            $query = $this->locations();
            $locations = $query->where($query->expr()->gt('c.mount_id', $query->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)))
                ->orderBy('c.mount_id')->setMaxResults(self::SCAN_LIMIT)->executeQuery()->fetchAllAssociative();
            foreach ($locations as $location) {
                $cursor = (int)$location['mount_id'];
                $this->removeLocation($location);
                ++$removed;
            }
        } while (count($locations) === self::SCAN_LIMIT);

        $cache = (new MetadataOnlyStorage())->getCache();
        if ($cache->getFolderContents('') === []) {
            $cache->remove('');
        }

        return $removed;
    }

    /** Caller serializes with creation through LifecycleService::write(). */
    public function reapOrphans(): array {
        $cursor = max(0, $this->config->getValueInt(Application::APP_ID, self::CURSOR_KEY, 0));
        $result = ['scanned' => 0, 'removed' => 0, 'busy' => 0, 'skipped' => 0,
            'cursor' => $cursor, 'wrapped' => false, 'scanLimitReached' => false, 'deletionLimitReached' => false];
        $locations = $this->orphanPage($cursor);
        if ($locations === [] && $cursor > 0) {
            $cursor = 0;
            $locations = $this->orphanPage(0);
            $result['wrapped'] = true;
        }

        foreach ($locations as $location) {
            $cursor = (int)$location['mount_id'];
            ++$result['scanned'];
            $key = 'archive_tools/connection/' . $cursor;
            try {
                $this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
            } catch (LockedException) {
                ++$result['busy'];
                continue;
            }

            try {
                $query = $this->locations(true);
                $current = $query->andWhere($query->expr()->eq('c.mount_id', $query->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)))
                    ->executeQuery()->fetchAssociative();
                if ($current === false) {
                    ++$result['skipped'];
                    continue;
                }
                $this->removeLocation($current);
                ++$result['removed'];
            } finally {
                $this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
            }
            if ($result['removed'] >= self::DELETE_LIMIT) {
                $result['deletionLimitReached'] = true;
                break;
            }
        }

        $result['scanLimitReached'] = count($locations) === self::SCAN_LIMIT && $result['scanned'] === self::SCAN_LIMIT;
        $result['cursor'] = $cursor;
        $this->config->setValueInt(Application::APP_ID, self::CURSOR_KEY, $cursor);
        return $result;
    }

    private function orphanPage(int $cursor): array {
        $query = $this->locations(true);
        return $query->andWhere($query->expr()->gt('c.mount_id', $query->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)))
            ->orderBy('c.mount_id')->setMaxResults(self::SCAN_LIMIT)->executeQuery()->fetchAllAssociative();
    }

    /** @return array<int,int> Positive cache projection evidence for legacy live connections. */
    public function legacyStorageIds(array $connections): array {
        $known = [];
        foreach ($connections as $connection) {
            $known[(int)$connection['id']] = true;
        }
        if ($known === []) {
            return [];
        }

        $candidates = [];
        foreach ($this->fileAccess->getDistinctMounts([MountProvider::class], false) as $entry) {
            $numeric = (int)$entry['storage_id'];
            $rootId = (int)$entry['root_id'];
            $root = $this->fileAccess->getByFileIdInStorage($rootId, $numeric);
            if ($numeric < 1 || $root?->getPath() !== '') {
                continue;
            }
            foreach ($this->mountCache->getMountsForRootId($rootId) as $mount) {
                $id = $mount->getMountId();
                if (isset($known[$id]) && $mount->getMountProvider() === MountProvider::class
                    && $mount->getStorageId() === $numeric && $mount->getRootInternalPath() === '') {
                    $candidates[$id][$numeric] = true;
                }
            }
        }

        $result = [];
        foreach ($candidates as $id => $storageIds) {
            if (count($storageIds) === 1) {
                $result[$id] = array_key_first($storageIds);
            }
        }
        return $result;
    }

    /** @return array<int,string> Existing shared locations from the earlier branch format. */
    public function previousSharedPaths(array $connections): array {
        if ($connections === []) {
            return [];
        }
        $cache = (new MetadataOnlyStorage())->getCache();
        $result = [];
        foreach ($connections as $connection) {
            $id = (int)$connection['id'];
            $path = ArchiveStorage::previousBranch($id, (string)$connection['source_etag']);
            if ($cache->getId($path) > 0) {
                $result[$id] = $path;
            }
        }
        return $result;
    }

    private function removeLocation(array $location): void {
        $id = (int)$location['mount_id'];
        $identity = (string)$location['cache_identity'];
        $path = (string)$location['cache_path'];
        $numeric = $location['cache_numeric_id'] === null ? null : (int)$location['cache_numeric_id'];
        if ($id < 1) {
            throw new \RuntimeException('Invalid archive cache location.');
        }

        if ($identity === ArchiveStorage::STORAGE_ID) {
            if (ArchiveStorage::parseBranch($path) !== $id) {
                throw new \RuntimeException('Invalid shared archive cache path.');
            }
            $this->removeBranch((new MetadataOnlyStorage())->getCache(), $path, $id);
        } elseif ($path === '' && preg_match('/\Aarchive_tools:' . $id . ':[a-f0-9]{24}\z/', $identity) === 1) {
            if ($numeric !== null) {
                $cache = (new MetadataOnlyStorage(['identity' => $identity]))->getCache();
                if ($numeric < 1 || $cache->getNumericStorageId() !== $numeric) {
                    throw new \RuntimeException('The legacy archive cache identity changed during cleanup.');
                }
                $this->mountCache->remoteStorageMounts($numeric);
                $cache->remove('');
                $this->emptyCache($cache);
            }
            // An unindexed legacy location has no safely identified numeric storage.
        } else {
            throw new \RuntimeException('Invalid archive cache location.');
        }

        $query = $this->db->getQueryBuilder();
        $query->delete('archive_tools_cache')->where($query->expr()->eq('mount_id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    private function removeBranch(ICache $cache, string $branch, int $connection): void {
        $root = $cache->get($branch);
        if ($root instanceof ICacheEntry) {
            foreach ($this->mountCache->getMountsForRootId($root->getId()) as $mount) {
                if ($mount->getMountId() === $connection && $mount->getMountProvider() === MountProvider::class
                    && $mount->getStorageId() === $root->getStorageId() && $mount->getRootInternalPath() === $branch) {
                    $this->mountCache->removeMount($mount->getMountPoint(), $mount->getUser());
                }
            }
            $cache->remove($branch);
        }
        $this->emptyCache($cache, $branch . '/');
    }

    private function emptyCache(ICache $cache, ?string $prefix = null): void {
        $pattern = $prefix === null ? '_%' : $prefix . '%';
        $previousPaths = [];
        $offset = 0;
        while (true) {
            $entries = $this->search($cache, $pattern, self::REMOVE_BATCH, $offset);
            if ($entries === []) {
                return;
            }
            $paths = [];
            foreach ($entries as $entry) {
                $path = $entry->getPath();
                if ($path !== '' && ($prefix === null || str_starts_with($path, $prefix))) {
                    $paths[] = $path;
                } else {
                    ++$offset;
                }
            }
            if ($paths !== [] && $paths === $previousPaths) {
                throw new \RuntimeException('The archive cache metadata could not be removed.');
            }
            foreach ($paths as $path) {
                $cache->remove($path);
            }
            $previousPaths = $paths;
        }
    }

    /** @return list<ICacheEntry> */
    private function search(ICache $cache, string $pattern, int $limit, int $offset): array {
        $comparison = new class ($pattern) implements ISearchComparison {
            private array $hints = [];

            public function __construct(private string $pattern) {}
            public function getType(): string { return self::COMPARE_LIKE_CASE_SENSITIVE; }
            public function getField(): string { return 'path'; }
            public function getExtra(): string { return ''; }
            public function getValue(): string { return $this->pattern; }
            public function getQueryHint(string $name, $default) { return $this->hints[$name] ?? $default; }
            public function setQueryHint(string $name, $value): void { $this->hints[$name] = $value; }
        };
        return $cache->searchQuery(new class ($comparison, $limit, $offset) implements ISearchQuery {
            public function __construct(private ISearchOperator $comparison, private int $limit, private int $offset) {}
            public function getSearchOperation(): ISearchOperator { return $this->comparison; }
            public function getLimit(): int { return $this->limit; }
            public function getOffset(): int { return $this->offset; }
            public function getOrder(): array {
                return [new class implements ISearchOrder {
                    public function getDirection(): string { return self::DIRECTION_ASCENDING; }
                    public function getField(): string { return 'fileid'; }
                    public function getExtra(): string { return ''; }
                    public function sortFileInfo(FileInfo $a, FileInfo $b): int { return $a->getId() <=> $b->getId(); }
                }];
            }
            public function getUser(): ?IUser { return null; }
            public function limitToHome(): bool { return false; }
            public function getSelectFields(): array { return []; }
        });
    }
}
