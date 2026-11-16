<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Storage;

use Closure;
use OC\Files\Cache\FailedCache;
use OC\Files\Cache\Wrapper\CacheWrapper;
use OCP\Files\Cache\ICache;
use OCP\Files\Cache\ICacheEntry;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOperator;

/**
 * Keep the scanner's cache reference stable while checking access for every
 * cache operation. A temporary failure during mount assembly must not replace
 * the real cache with a permanently captured FailedCache in that scanner.
 */
final class AccessCache extends CacheWrapper {
    public function __construct(
        ICache $cache,
        private readonly string $branch,
        private Closure $isAccessible,
        private Closure $readDirectory,
        private Closure $seedRoot,
    ) {
        parent::__construct($cache);
    }

    public function getCache(): ICache {
        return ($this->isAccessible)()
            ? parent::getCache()
            : new FailedCache(visible: false);
    }

    public function getNumericStorageId() {
        // MountPoint memoizes this identity even when the value is -1. It
        // must remain stable while access-dependent metadata stays gated.
        return parent::getCache()->getNumericStorageId();
    }

    public function getId($file) {
        // Core registers a mount only when this root identity is known. It
        // asks before the recipient's source share can be resolved, so gating
        // this identity would discard an already indexed shared connection.
        // Entry metadata, names, child IDs and all actual I/O remain gated.
        return $file === ''
            ? ($this->seedRoot)(parent::getCache())
            : parent::getId($this->nativePath((string)$file));
    }

    public function getFolderContentsById(int $fileId, ?string $mimeTypeFilter = null) {
        if ($this->getPathById($fileId) === null) {
            return [];
        }
        return ($this->readDirectory)(fn (): array => parent::getFolderContentsById($fileId, $mimeTypeFilter));
    }

    public function get($file) {
        return parent::get(is_int($file) ? $file : $this->nativePath((string)$file));
    }

    public function getParentId($file) {
        return $file === '' ? -1 : parent::getParentId($this->nativePath((string)$file));
    }

    public function inCache($file) {
        return parent::inCache($this->nativePath((string)$file));
    }

    public function getStatus($file) {
        return parent::getStatus($this->nativePath((string)$file));
    }

    public function getPathById($id) {
        $path = parent::getPathById($id);
        return is_string($path) ? $this->relativePath($path) : null;
    }

    protected function formatCacheEntry($entry) {
        if ($this->branch === '') {
            return $entry;
        }
        $path = $this->relativePath($entry->getPath());
        if ($path === null) {
            return false;
        }
        $entry = clone $entry;
        $entry['path'] = $path;
        if ($path === '') {
            $entry['name'] = '';
            $entry['parent'] = -1;
        }
        return $entry;
    }

    public function insert($file, array $data) {
        if ($file === '') {
            $id = $this->getId('');
            if ($id > 0) {
                $this->update($id, $data);
            }
            return $id;
        }
        return parent::insert($this->nativePath((string)$file), $this->entryData($data));
    }

    public function update($id, array $data) {
        if ($this->getPathById($id) !== null) {
            parent::update($id, $this->entryData($data));
        }
    }

    public function remove($file) {
        parent::remove($this->nativePath((string)$file));
    }

    public function clear() {
        $this->remove('');
    }

    public function move($source, $target) {
        throw new \LogicException('Archive connections are read-only.');
    }

    public function moveFromCache(ICache $sourceCache, $sourcePath, $targetPath) {
        throw new \LogicException('Archive connections are read-only.');
    }

    public function copyFromCache(ICache $sourceCache, ICacheEntry $sourceEntry, string $targetPath): int {
        throw new \LogicException('Archive connections are read-only.');
    }

    public function correctFolderSize(string $path, $data = null, bool $isBackgroundScan = false): void {
        if ($this->branch === '') {
            parent::correctFolderSize($path, $data, $isBackgroundScan);
            return;
        }
        parent::calculateFolderSize($this->nativePath($path));
    }

    public function calculateFolderSize($path, $entry = null) {
        return parent::calculateFolderSize($this->nativePath((string)$path), $this->branch === '' ? $entry : null);
    }

    public function getAll() {
        return $this->branch === '' && ($this->isAccessible)() ? parent::getAll() : [];
    }

    public function getIncomplete() {
        if ($this->branch === '') {
            return parent::getIncomplete();
        }
        return $this->getStatus('') === ICache::SHALLOW ? '' : false;
    }

    public function getQueryFilterForStorage(): ISearchOperator {
        if (!($this->isAccessible)()) {
            return new CacheSearchComparison(ISearchComparison::COMPARE_EQUAL, 'storage', -1);
        }
        $storageFilter = parent::getCache()->getQueryFilterForStorage();
        if ($this->branch === '') {
            return $storageFilter;
        }
        return new CacheSearchGroup(ISearchBinaryOperator::OPERATOR_AND, [
            $storageFilter,
            new CacheSearchGroup(ISearchBinaryOperator::OPERATOR_OR, [
                new CacheSearchComparison(ISearchComparison::COMPARE_EQUAL, 'path', $this->branch),
                new CacheSearchComparison(ISearchComparison::COMPARE_LIKE_CASE_SENSITIVE, 'path', $this->branch . '/%'),
            ]),
        ]);
    }

    public function getCacheEntryFromSearchResult(ICacheEntry $rawEntry): ?ICacheEntry {
        if (!($this->isAccessible)()) {
            return null;
        }
        return parent::getCacheEntryFromSearchResult($rawEntry);
    }

    private function nativePath(string $path): string {
        // Cache paths are normalized and relative. Preserve an empty alias for
        // the connection root without exposing the common storage root.
        if ($this->branch === '') {
            return $path;
        }
        return $path === '' ? $this->branch : $this->branch . '/' . $path;
    }

    private function relativePath(string $path): ?string {
        if ($this->branch === '') {
            return $path;
        }
        if ($path === $this->branch) {
            return '';
        }
        $prefix = $this->branch . '/';
        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : null;
    }

    private function entryData(array $data): array {
        // Native cache assigns canonical identity and parent links from the
        // stored path, never from the relative projection's metadata.
        unset($data['path'], $data['path_hash'], $data['name'], $data['parent'], $data['storage'], $data['storage_id']);
        return $data;
    }
}

/** @internal Minimal search operations over the public Nextcloud interfaces. */
abstract class CacheSearchOperation implements ISearchOperator {
    private array $hints = [];

    public function getQueryHint(string $name, $default) { return $this->hints[$name] ?? $default; }
    public function setQueryHint(string $name, $value): void { $this->hints[$name] = $value; }
}

/** @internal */
final class CacheSearchComparison extends CacheSearchOperation implements ISearchComparison {
    public function __construct(private readonly string $type, private readonly string $field, private readonly string|int $value) {}
    public function getType(): string { return $this->type; }
    public function getField(): string { return $this->field; }
    public function getExtra(): string { return ''; }
    public function getValue(): string|int { return $this->value; }
    // Core's query optimizer groups equivalent operators by their string form.
    public function __toString(): string { return $this->field . ' ' . $this->type . ' ' . json_encode($this->value); }
}

/** @internal */
final class CacheSearchGroup extends CacheSearchOperation implements ISearchBinaryOperator {
    public function __construct(private readonly string $type, private readonly array $arguments) {}
    public function getType(): string { return $this->type; }
    public function getArguments(): array { return $this->arguments; }
    public function __toString(): string { return '(' . implode(' ' . $this->type . ' ', $this->arguments) . ')'; }
}
