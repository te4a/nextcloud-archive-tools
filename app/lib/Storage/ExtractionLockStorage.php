<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Storage;

use OC\Files\Storage\Wrapper\Wrapper;
use OCP\Files\Storage\IStorage;
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
final class ExtractionLockStorage extends Wrapper {
    private bool $active = true;
    private array $reservedPaths = [];

    public function __construct(IStorage $storage, string $parentPath) {
        parent::__construct(['storage' => $storage]);
        $this->reserve($parentPath);
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
        if (!$this->isReserved($path)) { parent::acquireLock($path, $type, $provider); }
    }

    public function releaseLock(string $path, int $type, ILockingProvider $provider): void {
        if (!$this->isReserved($path)) { parent::releaseLock($path, $type, $provider); }
    }

    public function changeLock(string $path, int $type, ILockingProvider $provider): void {
        if (!$this->isReserved($path)) { parent::changeLock($path, $type, $provider); }
    }
}
