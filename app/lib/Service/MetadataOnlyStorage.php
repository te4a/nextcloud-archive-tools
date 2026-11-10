<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OC\Files\Storage\Common;

/** Native cache bridge with no physical storage and no file I/O capability. */
final class MetadataOnlyStorage extends Common {
    private string $identity;

    public function __construct(array $parameters) {
        parent::__construct($parameters);
        if (!is_string($parameters['identity'] ?? null) || !str_starts_with($parameters['identity'], 'archive_tools:')) {
            throw new \InvalidArgumentException('Only Archive Tools cache identities are supported.');
        }
        $this->identity = $parameters['identity'];
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
