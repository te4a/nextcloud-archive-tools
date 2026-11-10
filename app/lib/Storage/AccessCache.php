<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Storage;

use Closure;
use OC\Files\Cache\FailedCache;
use OC\Files\Cache\Wrapper\CacheWrapper;
use OCP\Files\Cache\ICache;

/**
 * Keep the scanner's cache reference stable while checking access for every
 * cache operation. A temporary failure during mount assembly must not replace
 * the real cache with a permanently captured FailedCache in that scanner.
 */
final class AccessCache extends CacheWrapper {
    public function __construct(ICache $cache, private Closure $isAccessible, private Closure $readDirectory) {
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
            ? parent::getCache()->getId('')
            : parent::getId($file);
    }

    public function getFolderContentsById(int $fileId, ?string $mimeTypeFilter = null) {
        if (!($this->isAccessible)()) {
            return [];
        }
        return ($this->readDirectory)(fn (): array => parent::getFolderContentsById($fileId, $mimeTypeFilter));
    }
}
