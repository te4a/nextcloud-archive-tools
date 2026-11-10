<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Storage;

use Icewind\Streams\IteratorDirectory;
use OC\Files\Storage\Common;
use OCA\ArchiveTools\Archive\ArchiveReader;
use OCA\ArchiveTools\Service\MountRepository;
use OCA\ArchiveTools\Service\LifecycleService;
use OCA\ArchiveTools\Service\LifecycleLease;
use OCP\Constants;
use OCP\Files\Cache\ICache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
use OCP\Files\StorageNotAvailableException;
use OCP\Security\ICrypto;
use OCP\Lock\ILockingProvider;
use OCP\IRequest;
use OCP\Server;

/** Read-only archive storage; its filecache never adds bytes to home storage. */
final class ArchiveStorage extends Common {
    private array $mount;
    private string $viewer;
    private MountRepository $repository;
    private IRootFolder $rootFolder;
    private ArchiveReader $reader;
    private ICrypto $crypto;
    private IMimeTypeDetector $mimeTypes;
    private ?File $source = null;
    private ?array $entries = null;
    private ?string $password = null;
    private bool $accessResolved = false;
    private bool $resolvingAccess = false;
    /** @var array<int, AccessCache> */
    private array $accessCaches = [];
    private ?LifecycleLease $lifecycleLease = null;

    public function __construct(array $parameters) {
        parent::__construct($parameters);
        $this->mount = $parameters['mount'];
        $this->viewer = $parameters['viewer'];
        $this->repository = $parameters['repository'];
        $this->rootFolder = $parameters['rootFolder'];
        $this->reader = $parameters['reader'];
        $this->crypto = $parameters['crypto'];
        $this->mimeTypes = $parameters['mimeTypes'];
    }

    public function getId(): string {
        // Stable for all recipients, distinct when the indexed source changes.
        return 'archive_tools:' . (int)$this->mount['id'] . ':'
            . substr(hash('sha256', (string)$this->mount['source_etag']), 0, 24);
    }

    /** Gate cached listings too: checking only fopen() would disclose stale names. */
    public function getCache(string $path = '', ?IStorage $storage = null): ICache {
        $this->ensureLifecycle();
        // Do not resolve files while constructing a cache/scanner. Shares may
        // still be assembling; the persistent gate checks actual operations.
        $cache = parent::getCache($path, $storage);
        $key = spl_object_id($cache);
        return $this->accessCaches[$key] ??= new AccessCache(
            $cache,
            fn (): bool => $this->available(),
            function (callable $read): array {
                return $this->repository->withShared((int)$this->mount['id'], function () use ($read): array {
                    $result = $read();
                    if ($this->countsAsActivity()) {
                        $this->repository->touch((int)$this->mount['id']);
                    }
                    return $result;
                });
            },
        );
    }

    public function test(): bool {
        return $this->available();
    }

    public function file_exists(string $path): bool {
        return $this->entry($path) !== null;
    }

    public function is_dir(string $path): bool {
        return ($this->entry($path)['type'] ?? null) === 'dir';
    }

    public function is_file(string $path): bool {
        return ($this->entry($path)['type'] ?? null) === 'file';
    }

    public function filetype(string $path): string|false {
        return $this->entry($path)['type'] ?? false;
    }

    public function filesize(string $path): int|float|false {
        $entry = $this->entry($path);
        return $entry === null ? false : (int)$entry['size'];
    }

    public function filemtime(string $path): int|false {
        $entry = $this->entry($path);
        return $entry === null ? false : (int)$entry['mtime'];
    }

    public function stat(string $path): array|false {
        $entry = $this->entry($path);
        if ($entry === null) {
            return false;
        }
        return ['size' => (int)$entry['size'], 'mtime' => (int)$entry['mtime']];
    }

    public function getPermissions(string $path): int {
        return $this->file_exists($path) ? Constants::PERMISSION_READ : 0;
    }

    public function isReadable(string $path): bool {
        return $this->file_exists($path);
    }

    public function isUpdatable(string $path): bool { return false; }
    public function isCreatable(string $path): bool { return false; }
    public function isDeletable(string $path): bool { return false; }
    public function isSharable(string $path): bool { return false; }
    public function mkdir(string $path): bool { return false; }
    public function rmdir(string $path): bool { return false; }
    public function unlink(string $path): bool { return false; }
    public function touch(string $path, ?int $mtime = null): bool { return false; }
    public function rename(string $source, string $target): bool { return false; }
    public function copy(string $source, string $target): bool { return false; }
    public function isLocal(): bool { return false; }

    public function getOwner(string $path): string|false {
        return (string)$this->mount['created_by'];
    }

    public function getETag(string $path): string|false {
        $normalized = self::normalizePath($path);
        $entry = $this->entry($path);
        if ($entry === null || $normalized === null) {
            return false;
        }
        // Core filecache.etag is varchar(40). PostgreSQL enforces this bound;
        // SQLite accepts longer values and would hide an incompatible index.
        return substr(hash('sha256', $this->getId() . '/' . $normalized . ':' . ($entry['crc'] ?? '') . ':' . $entry['size']), 0, 40);
    }

    public function getMimeType(string $path): string|false {
        $entry = $this->entry($path);
        if ($entry === null) {
            return false;
        }
        return $entry['type'] === 'dir' ? 'httpd/unix-directory' : $this->mimeTypes->detectPath($path);
    }

    public function hasUpdated(string $path, int $time): bool {
        $mtime = $this->filemtime($path);
        return $mtime !== false && $mtime > $time;
    }

    public function opendir(string $path) {
        $normalized = self::normalizePath($path);
        if ($normalized === null || !$this->is_dir($path)) {
            return false;
        }
        $prefix = $normalized === '' ? '' : $normalized . '/';
        $names = [];
        foreach ($this->entries as $entryPath => $_entry) {
            // PHP converts numeric-string array keys (e.g. ZIP entry "2026")
            // to integers; filesystem path functions require strings here.
            $entryPath = (string)$entryPath;
            if ($entryPath === $normalized || !str_starts_with($entryPath, $prefix)) {
                continue;
            }
            $remaining = substr($entryPath, strlen($prefix));
            if ($remaining !== '' && !str_contains($remaining, '/')) {
                $names[] = $remaining;
            }
        }
        sort($names, SORT_STRING);
        if ($this->countsAsActivity()) {
            $this->repository->touch((int)$this->mount['id']);
        }
        return IteratorDirectory::wrap($names);
    }

    public function fopen(string $path, string $mode) {
        if ($mode !== 'r' && $mode !== 'rb') {
            return false;
        }
        return $this->readStream($path);
    }

    public function readStream(string $path) {
        $activity = Server::get(LifecycleService::class)->enter();
        $lease = null;
        $locked = false;
        $stream = null;
        try {
            $this->resolveAccess();
            $entry = $this->entry($path);
            if ($entry === null || $entry['type'] !== 'file' || !is_string($entry['originalName'] ?? null)) {
                $activity->close();
                return false;
            }
            $lease = $this->repository->beginReadLease((int)$this->mount['id'], $this->countsAsActivity());
            $this->source->lock(ILockingProvider::LOCK_SHARED);
            $locked = true;
            // Reload the cache entry after taking the source lock. The Node
            // object may have been resolved before a concurrent replacement.
            $sourceStorage = $this->source->getStorage();
            $sourcePath = $this->source->getInternalPath();
            $fresh = $sourceStorage->getCache()->get($sourcePath);
            if (!$fresh || !hash_equals((string)$this->mount['source_etag'], $fresh->getEtag())) {
                throw new StorageNotAvailableException('The archive source changed. Open the archive again.');
            }
            $localPath = $sourceStorage->getLocalFile($sourcePath);
            if (!is_string($localPath) || $localPath === '') {
                throw new StorageNotAvailableException('The archive source is unavailable.');
            }
            $stream = $this->reader->stream($localPath, $entry['originalName'], $this->password);
            if (!is_resource($stream)) {
                throw new StorageNotAvailableException('The archive entry could not be read.');
            }
            $source = $this->source;
            return LeasedStream::wrap(
                $stream,
                fn () => $this->repository->renewReadLease($lease),
                function () use ($source, $lease, $activity): void {
                    try {
                        $source->unlock(ILockingProvider::LOCK_SHARED);
                    } finally {
                        try { $this->repository->endReadLease($lease); }
                        finally { $activity->close(); }
                    }
                },
            );
        } catch (\Throwable $error) {
            try {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            } finally {
                try {
                    if ($locked) {
                        $this->source->unlock(ILockingProvider::LOCK_SHARED);
                    }
                } finally {
                    try { if ($lease !== null) { $this->repository->endReadLease($lease); } }
                    finally { $activity->close(); }
                }
            }
            throw $error;
        }
    }

    private function countsAsActivity(): bool {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        $path = Server::get(IRequest::class)->getPathInfo();
        return !str_contains($path, '/core/preview')
            && !str_contains($path, '/thumbnail');
    }

    private function entry(string $path): ?array {
        $normalized = self::normalizePath($path);
        if ($normalized === null || !$this->available()) {
            return null;
        }
        return $this->entries[$normalized] ?? null;
    }

    private static function normalizePath(string $path): ?string {
        if (str_contains($path, "\0") || str_contains($path, '\\')) {
            return null;
        }
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }
        foreach (explode('/', $path) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                return null;
            }
        }
        return $path;
    }

    private function available(): bool {
        try {
            $this->ensureLifecycle();
            $this->resolveAccess();
            return true;
        } catch (StorageNotAvailableException) {
            return false;
        }
    }

    private function ensureLifecycle(): void {
        try {
            if ($this->lifecycleLease === null) { $this->lifecycleLease = Server::get(LifecycleService::class)->enter(); }
            else { Server::get(LifecycleService::class)->assertReady(); }
        } catch (\Throwable) {
            throw new StorageNotAvailableException('The archive connection is unavailable during application cleanup.');
        }
    }

    public function __destruct() { try { $this->lifecycleLease?->close(); } catch (\Throwable) {} }

    private function resolveAccess(): void {
        if ($this->accessResolved) {
            return;
        }
        if ($this->resolvingAccess) {
            throw new StorageNotAvailableException('The archive source is still being resolved.');
        }
        $this->resolvingAccess = true;
        try {
            $current = $this->repository->find((int)$this->mount['id']);
            if ($current === null
                || (int)$current['source_id'] !== (int)$this->mount['source_id']
                || (int)$current['parent_id'] !== (int)$this->mount['parent_id']
                || !hash_equals((string)$this->mount['source_etag'], (string)$current['source_etag'])) {
                throw new \RuntimeException('The archive connection changed.');
            }

            $folder = $this->rootFolder->getUserFolder($this->viewer);
            $parentReadable = false;
            foreach ($folder->getById((int)$this->mount['parent_id']) as $node) {
                if ($node instanceof Folder && $node->isReadable()) {
                    $parentReadable = true;
                    break;
                }
            }
            if (!$parentReadable) {
                throw new \RuntimeException('The archive parent is inaccessible.');
            }
            foreach ($folder->getById((int)$this->mount['source_id']) as $node) {
                if ($node instanceof File && $node->isReadable()
                    && $node->getParent()->getId() === (int)$this->mount['parent_id']) {
                    $this->source = $node;
                    break;
                }
            }
            if ($this->source === null
                || !hash_equals((string)$this->mount['source_etag'], $this->source->getEtag())) {
                throw new \RuntimeException('The archive source changed or is inaccessible.');
            }

            $index = json_decode((string)$current['index_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($index['entries'] ?? null)) {
                throw new \RuntimeException('The archive index is invalid.');
            }
            $rootTime = $this->source->getMTime();
            $entries = ['' => ['type' => 'dir', 'size' => 0, 'mtime' => $rootTime, 'originalName' => null]];
            foreach ($index['entries'] as $path => $metadata) {
                $normalized = self::normalizePath((string)$path);
                if ($normalized === null || $normalized === '' || !is_array($metadata)
                    || !in_array($metadata['type'] ?? null, ['file', 'dir'], true)) {
                    throw new \RuntimeException('The archive index contains an invalid entry.');
                }
                $entries[$normalized] = $metadata + ['size' => 0, 'mtime' => $rootTime];
                $parent = dirname($normalized);
                while ($parent !== '.' && $parent !== '') {
                    $entries[$parent] ??= ['type' => 'dir', 'size' => 0, 'mtime' => $rootTime, 'originalName' => null];
                    $parent = dirname($parent);
                }
            }
            $this->password = empty($current['password']) ? null : $this->crypto->decrypt((string)$current['password']);
            $this->entries = $entries;
            $this->accessResolved = true;
        } catch (\Throwable) {
            $this->source = null;
            // The failure may happen before core finishes assembling shares;
            // do not memoize failure, and never return the persisted index.
            throw new StorageNotAvailableException('The archive source is unavailable, changed, or no longer accessible.');
        } finally {
            $this->resolvingAccess = false;
        }
    }
}
