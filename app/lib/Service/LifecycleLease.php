<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OCP\Lock\ILockingProvider;

/** Keeps the application cleanup barrier held until an operation/stream closes. */
final class LifecycleLease {
    private bool $closed = false;

    public function __construct(private ILockingProvider $locks, private string $key) {}

    public function close(): void {
        if ($this->closed) { return; }
        $this->closed = true;
        $this->locks->releaseLock($this->key, ILockingProvider::LOCK_SHARED);
    }

    public function __destruct() {
        try { $this->close(); } catch (\Throwable) { /* An explicit close remains the authoritative result. */ }
    }
}
