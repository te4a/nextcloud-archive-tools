<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Storage;

use Closure;
use OCP\Files\Cache\ICache;
use OCP\Files\Cache\ICacheEntry;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchQuery;
use OCP\IUser;

/** Present a connection's native cache in its mount-relative namespace. */
final class AccessCache implements ICache {
    public function __construct(
        private readonly ICache $cache,
        private readonly string $branch,
        private Closure $isAccessible,
        private Closure $readDirectory,
        private Closure $seedRoot,
    ) {}

    public function getNumericStorageId(): int {
        // Core memoizes the storage ID during mount assembly, before shares are ready.
        return $this->cache->getNumericStorageId();
    }

    public function getId($file): int {
        // Registration needs the root identity even when the viewer's source share
        // is still assembling. Child IDs and all entry metadata remain gated.
        if ($file === '') {
            return ($this->seedRoot)($this->cache);
        }
        return ($this->isAccessible)() ? $this->cache->getId($this->nativePath((string)$file)) : -1;
    }

    public function get($file): ICacheEntry|false {
        if (!($this->isAccessible)()) {
            return false;
        }
        $entry = $this->cache->get(is_int($file) ? $file : $this->nativePath((string)$file));
        return $entry instanceof ICacheEntry ? $this->formatCacheEntry($entry) : false;
    }

    public function getFolderContents(string $folder, ?string $mimeTypeFilter = null): array {
        return $this->getFolderContentsById($this->getId($folder), $mimeTypeFilter);
    }

    public function getFolderContentsById(int $fileId, ?string $mimeTypeFilter = null): array {
        if ($this->getPathById($fileId) === null) {
            return [];
        }
        return ($this->readDirectory)(fn (): array => ($this->isAccessible)()
            ? $this->formatEntries($this->cache->getFolderContentsById($fileId, $mimeTypeFilter))
            : []);
    }

    public function getParentId($file): int {
        if ($file === '' || !($this->isAccessible)()) {
            return -1;
        }
        return $this->cache->getParentId($this->nativePath((string)$file));
    }

    public function inCache($file): bool {
        return ($this->isAccessible)() && $this->cache->inCache($this->nativePath((string)$file));
    }

    public function getStatus($file): int {
        return ($this->isAccessible)() ? $this->cache->getStatus($this->nativePath((string)$file)) : ICache::NOT_FOUND;
    }

    public function getPathById($id): ?string {
        if (!($this->isAccessible)()) {
            return null;
        }
        $path = $this->cache->getPathById($id);
        return is_string($path) ? $this->relativePath($path) : null;
    }

    public function put($file, array $data): int {
        $id = $this->getId($file);
        if ($id > -1) {
            $this->update($id, $data);
            return $id;
        }
        return $this->insert($file, $data);
    }

    public function insert($file, array $data): int {
        if ($file === '') {
            $id = $this->getId('');
            if ($id > 0) {
                $this->update($id, $data);
            }
            return $id;
        }
        return ($this->isAccessible)()
            ? $this->cache->insert($this->nativePath((string)$file), $this->entryData($data))
            : -1;
    }

    public function update($id, array $data): void {
        if ($this->getPathById($id) !== null) {
            $this->cache->update($id, $this->entryData($data));
        }
    }

    public function remove($file): void {
        if (($this->isAccessible)()) {
            $this->cache->remove($this->nativePath((string)$file));
        }
    }

    public function move($source, $target): void {
        throw new \LogicException('Archive connections are read-only.');
    }

    public function moveFromCache(ICache $sourceCache, $sourcePath, $targetPath): void {
        throw new \LogicException('Archive connections are read-only.');
    }

    public function copyFromCache(ICache $sourceCache, ICacheEntry $sourceEntry, string $targetPath): int {
        throw new \LogicException('Archive connections are read-only.');
    }

    public function getIncomplete(): string|false {
        if (!($this->isAccessible)()) {
            return false;
        }
        if ($this->branch === '') {
            return $this->cache->getIncomplete();
        }
        return $this->cache->getStatus($this->branch) === ICache::SHALLOW ? '' : false;
    }

    public function normalize($path): string {
        return $this->cache->normalize($path);
    }

    public function search($pattern): array {
        return $this->searchQuery(new CacheSearchQuery(
            new CacheSearchComparison(ISearchComparison::COMPARE_LIKE, 'name', (string)$pattern),
        ));
    }

    public function searchByMime($mimetype): array {
        $exact = str_contains($mimetype, '/');
        return $this->searchQuery(new CacheSearchQuery(new CacheSearchComparison(
            $exact ? ISearchComparison::COMPARE_EQUAL : ISearchComparison::COMPARE_LIKE,
            'mimetype',
            $exact ? $mimetype : $mimetype . '/%',
        )));
    }

    public function searchQuery(ISearchQuery $query): array {
        if (!($this->isAccessible)()) {
            return [];
        }
        if ($this->branch !== '') {
            $query = new CacheSearchQuery(new CacheSearchGroup(ISearchBinaryOperator::OPERATOR_AND, [
                $query->getSearchOperation(),
                $this->branchFilter(),
            ]), $query);
        }
        return $this->formatEntries($this->cache->searchQuery($query));
    }

    public function getQueryFilterForStorage(): ISearchOperator {
        if (!($this->isAccessible)()) {
            return new CacheSearchComparison(ISearchComparison::COMPARE_EQUAL, 'storage', -1);
        }
        $storageFilter = $this->cache->getQueryFilterForStorage();
        return $this->branch === '' ? $storageFilter
            : new CacheSearchGroup(ISearchBinaryOperator::OPERATOR_AND, [$storageFilter, $this->branchFilter()]);
    }

    public function getCacheEntryFromSearchResult(ICacheEntry $rawEntry): ?ICacheEntry {
        if (!($this->isAccessible)()) {
            return null;
        }
        $entry = $this->cache->getCacheEntryFromSearchResult($rawEntry);
        if ($entry === null) {
            return null;
        }
        // Global search can project the same native entry through several caches.
        return $this->branch === '' ? clone $entry : ($this->formatCacheEntry($entry) ?: null);
    }

    private function branchFilter(): ISearchOperator {
        return new CacheSearchGroup(ISearchBinaryOperator::OPERATOR_OR, [
            new CacheSearchComparison(ISearchComparison::COMPARE_EQUAL, 'path', $this->branch),
            new CacheSearchComparison(ISearchComparison::COMPARE_LIKE_CASE_SENSITIVE, 'path', $this->branch . '/%'),
        ]);
    }

    private function formatCacheEntry(ICacheEntry $entry): ICacheEntry|false {
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

    private function formatEntries(array $entries): array {
        return array_filter(array_map($this->formatCacheEntry(...), $entries));
    }

    private function nativePath(string $path): string {
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
        // Native cache assigns canonical identity and parent links from the stored path.
        unset($data['path'], $data['path_hash'], $data['name'], $data['parent'], $data['storage'], $data['storage_id']);
        return $data;
    }
}

/** @internal Supplies a scoped search operation without changing its paging or metadata options. */
final class CacheSearchQuery implements ISearchQuery {
    public function __construct(private readonly ISearchOperator $operation, private readonly ?ISearchQuery $original = null) {}
    public function getSearchOperation(): ISearchOperator { return $this->operation; }
    public function getLimit(): int { return $this->original?->getLimit() ?? 0; }
    public function getOffset(): int { return $this->original?->getOffset() ?? 0; }
    public function getOrder(): array { return $this->original?->getOrder() ?? []; }
    public function getUser(): ?IUser { return $this->original?->getUser(); }
    public function limitToHome(): bool { return $this->original?->limitToHome() ?? false; }
    public function getSelectFields(): array { return $this->original?->getSelectFields() ?? []; }
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
