<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Storage;

use OC\Files\Storage\Common;
use OCP\Files\Cache\ICache;
use OCP\Files\Cache\IPropagator;
use OCP\Files\Cache\IScanner;
use OCP\Files\Cache\IUpdater;
use OCP\Files\Cache\IWatcher;
use OCP\Files\Storage\ILockingStorage;
use OCP\Files\Storage\IStorage;
use OCP\Files\Storage\IWriteStreamStorage;
use OCP\Lock\ILockingProvider;

/**
 * Let this worker use its exclusively reserved destination paths.
 *
 * Native View operations take shared locks on every ancestor. Core providers
 * do not treat an exclusive lock held by the same process as a shared lock.
 * Calls for a reserved path are already satisfied by its exclusive lock.
 * Downgrades and releases from nested native operations are omitted too, so
 * the real lock keeps protecting other requests until the worker releases it
 * explicitly through the original storage.
 */
final class ExtractionLockStorage extends Common {
    private readonly IStorage&ILockingStorage $wrapped;
    private bool $active = true;
    private array $reservedPaths = [];

    /** @param array{storage: IStorage&ILockingStorage, parentPath: string} $parameters */
    public function __construct(array $parameters) {
        parent::__construct([]);
        $this->wrapped = $parameters['storage'];
        $this->reserve($parameters['parentPath']);
    }

    /** Call only after acquiring the real exclusive lock on this path. */
    public function reserve(string $path): void {
        $this->reservedPaths[trim($path, '/')] = true;
    }

    public function deactivate(): void {
        $this->active = false;
    }

    private function isReserved(string $path): bool {
        return $this->active && isset($this->reservedPaths[trim($path, '/')]);
    }

    public function acquireLock(string $path, int $type, ILockingProvider $provider): void {
        if (!$this->isReserved($path)) { $this->wrapped->acquireLock($path, $type, $provider); }
    }

    public function releaseLock(string $path, int $type, ILockingProvider $provider): void {
        if (!$this->isReserved($path)) { $this->wrapped->releaseLock($path, $type, $provider); }
    }

    public function changeLock(string $path, int $type, ILockingProvider $provider): void {
        if (!$this->isReserved($path)) { $this->wrapped->changeLock($path, $type, $provider); }
    }

    // The documented Common base satisfies the core's native storage type.
    // Delegate to the existing mounted storage so sharing, cache identity, and
    // any backend wrappers keep applying inside the worker.
    public function getId(): string {
        return $this->wrapped->getId();
    }

    public function mkdir(string $path): bool {
        return $this->wrapped->mkdir($path);
    }

    public function rmdir(string $path): bool {
        return $this->wrapped->rmdir($path);
    }

    public function opendir(string $path) {
        return $this->wrapped->opendir($path);
    }

    public function is_dir(string $path): bool {
        return $this->wrapped->is_dir($path);
    }

    public function is_file(string $path): bool {
        return $this->wrapped->is_file($path);
    }

    public function stat(string $path): array|false {
        return $this->wrapped->stat($path);
    }

    public function filetype(string $path): string|false {
        return $this->wrapped->filetype($path);
    }

    public function filesize(string $path): int|float|false {
        return $this->wrapped->filesize($path);
    }

    public function isCreatable(string $path): bool {
        return $this->wrapped->isCreatable($path);
    }

    public function isReadable(string $path): bool {
        return $this->wrapped->isReadable($path);
    }

    public function isUpdatable(string $path): bool {
        return $this->wrapped->isUpdatable($path);
    }

    public function isDeletable(string $path): bool {
        return $this->wrapped->isDeletable($path);
    }

    public function isSharable(string $path): bool {
        return $this->wrapped->isSharable($path);
    }

    public function getPermissions(string $path): int {
        return $this->wrapped->getPermissions($path);
    }

    public function file_exists(string $path): bool {
        return $this->wrapped->file_exists($path);
    }

    public function filemtime(string $path): int|false {
        return $this->wrapped->filemtime($path);
    }

    public function file_get_contents(string $path): string|false {
        return $this->wrapped->file_get_contents($path);
    }

    public function file_put_contents(string $path, mixed $data): int|float|false {
        return $this->wrapped->file_put_contents($path, $data);
    }

    public function unlink(string $path): bool {
        return $this->wrapped->unlink($path);
    }

    public function rename(string $source, string $target): bool {
        return $this->wrapped->rename($source, $target);
    }

    public function copy(string $source, string $target): bool {
        return $this->wrapped->copy($source, $target);
    }

    public function fopen(string $path, string $mode) {
        return $this->wrapped->fopen($path, $mode);
    }

    public function getMimeType(string $path): string|false {
        return $this->wrapped->getMimeType($path);
    }

    public function hash(string $type, string $path, bool $raw = false): string|false {
        return $this->wrapped->hash($type, $path, $raw);
    }

    public function free_space(string $path): int|float|false {
        return $this->wrapped->free_space($path);
    }

    public function touch(string $path, ?int $mtime = null): bool {
        return $this->wrapped->touch($path, $mtime);
    }

    public function getLocalFile(string $path): string|false {
        return $this->wrapped->getLocalFile($path);
    }

    public function hasUpdated(string $path, int $time): bool {
        return $this->wrapped->hasUpdated($path, $time);
    }

    public function getETag(string $path): string|false {
        return $this->wrapped->getETag($path);
    }

    public function isLocal(): bool {
        return $this->wrapped->isLocal();
    }

    public function getDirectDownload(string $path): array|false {
        return $this->wrapped->getDirectDownload($path);
    }

    public function getDirectDownloadById(string $fileId): array|false {
        return $this->wrapped->getDirectDownloadById($fileId);
    }

    public function verifyPath(string $path, string $fileName): void {
        $this->wrapped->verifyPath($path, $fileName);
    }

    public function copyFromStorage(IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath, bool $preserveMtime = false): bool {
        if ($sourceStorage === $this) {
            return $this->copy($sourceInternalPath, $targetInternalPath);
        }
        return $this->wrapped->copyFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
    }

    public function moveFromStorage(IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool {
        // A move to the user's trash may use this same mount for both paths.
        if ($sourceStorage === $this) {
            return $this->rename($sourceInternalPath, $targetInternalPath);
        }
        return $this->wrapped->moveFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
    }

    public function test(): bool {
        return $this->wrapped->test();
    }

    public function getAvailability(): array {
        return $this->wrapped->getAvailability();
    }

    public function setAvailability(bool $isAvailable): void {
        $this->wrapped->setAvailability($isAvailable);
    }

    public function needsPartFile(): bool {
        return $this->wrapped->needsPartFile();
    }

    public function getOwner(string $path): string|false {
        return $this->wrapped->getOwner($path);
    }

    public function setOwner(?string $user): void {
        $this->wrapped->setOwner($user);
    }

    public function getCache(string $path = '', ?IStorage $storage = null): ICache {
        return $this->wrapped->getCache($path, $storage);
    }

    public function getScanner(string $path = '', ?IStorage $storage = null): IScanner {
        return $this->wrapped->getScanner();
    }

    public function getWatcher(string $path = '', ?IStorage $storage = null): IWatcher {
        return $this->wrapped->getWatcher();
    }

    public function getPropagator(?IStorage $storage = null): IPropagator {
        return $this->wrapped->getPropagator();
    }

    public function getUpdater(?IStorage $storage = null): IUpdater {
        return $this->wrapped->getUpdater();
    }

    public function writeStream(string $path, $stream, ?int $size = null): int {
        if ($this->wrapped instanceof IWriteStreamStorage) {
            return $this->wrapped->writeStream($path, $stream, $size);
        }
        return parent::writeStream($path, $stream, $size);
    }
}
