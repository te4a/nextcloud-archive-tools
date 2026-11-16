<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OC\Files\Storage\Common;
use OCA\ArchiveTools\Storage\ArchiveStorage;

/** Native cache bridge with no physical storage and no file I/O capability. */
final class MetadataOnlyStorage extends Common {
    private string $identity;

    public function __construct(array $parameters = []) {
        parent::__construct($parameters);
        $identity = $parameters['identity'] ?? ArchiveStorage::STORAGE_ID;
        if (!is_string($identity) || ($identity !== ArchiveStorage::STORAGE_ID
            && preg_match('/^archive_tools:[1-9][0-9]{0,18}:[a-f0-9]{24}$/D', $identity) !== 1)) {
            throw new \InvalidArgumentException('Only Archive Tools cache identities are supported.');
        }
        $this->identity = $identity;
    }

    public static function legacyIdentity(int $connection, string $etag): string {
        if ($connection < 1) {
            throw new \InvalidArgumentException('Invalid archive connection identity.');
        }
        return 'archive_tools:' . $connection . ':' . substr(hash('sha256', $etag), 0, 24);
    }

    public function getId(): string { return $this->identity; }
    public function mkdir(string $path) { return false; }
    public function rmdir(string $path) { return false; }
    public function opendir(string $path) { return false; }
    public function stat(string $path) { return false; }
    public function filetype(string $path) { return false; }
    public function file_exists(string $path) { return false; }
    public function unlink(string $path) { return false; }
    public function fopen(string $path, string $mode) { return false; }
    public function touch(string $path, ?int $mtime = null) { return false; }
}
