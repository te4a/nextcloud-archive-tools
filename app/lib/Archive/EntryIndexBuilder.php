<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

use Normalizer;
use RuntimeException;

/** Shared, bounded index policy. Archive names are never filesystem paths. */
final class EntryIndexBuilder {
    private const MAX_COMPONENT_BYTES = 250;

    private array $entries = [];
    private array $rawPaths = [];
    private array $explicitPaths = [];
    private int $entryCount = 0;
    private int $fileCount = 0;
    private int $totalSize = 0;
    private int $packedSize = 0;
    private int $nameBytes = 0;
    private bool $encrypted = false;

    public function __construct(
        private readonly int $maxEntries = 10000,
        private readonly int $maxExpandedBytes = 4294967296,
        private readonly int $maxDepth = 32,
        private readonly int $maxIndexNameBytes = 8388608,
    ) {
        if (PHP_INT_SIZE < 8 || min($maxEntries, $maxExpandedBytes, $maxDepth, $maxIndexNameBytes) < 1) {
            throw new RuntimeException('Archive limits require positive values and 64-bit PHP.');
        }
        if (!class_exists(Normalizer::class)) {
            throw new RuntimeException('Archive names require the PHP intl extension.');
        }
    }

    public function add(string $name, string $type, int $size, int $mtime = 0, ?int $crc = null, bool $encrypted = false, int $compressedSize = 0): void {
        if (!in_array($type, ['file', 'dir'], true)) {
            throw new RuntimeException('Links and special files are not supported in archives.');
        }
        if (++$this->entryCount > $this->maxEntries) {
            throw new RuntimeException('The archive contains too many entries.');
        }
        $this->consumeNameBytes(strlen($name));
        // TAR tools commonly include the archive root as an explicit ./ entry.
        if ($type === 'dir' && in_array($name, ['.', './'], true)) {
            if (isset($this->explicitPaths[''])) {
                throw new RuntimeException('The archive contains duplicate entry names.');
            }
            $this->explicitPaths[''] = true;
            return;
        }
        [$path, $rawParts, $parts] = $this->normalizePath($name);
        if (isset($this->explicitPaths[$path])) {
            throw new RuntimeException('The archive contains duplicate or colliding entry names.');
        }
        $this->explicitPaths[$path] = true;
        foreach ($parts as $position => $_part) {
            $prefix = implode('/', array_slice($parts, 0, $position + 1));
            $rawPrefix = implode('/', array_slice($rawParts, 0, $position + 1));
            if (isset($this->rawPaths[$prefix]) && $this->rawPaths[$prefix] !== $rawPrefix) {
                throw new RuntimeException('The archive contains names that collide after Unicode normalization.');
            }
            $this->rawPaths[$prefix] = $rawPrefix;
            if ($position < count($parts) - 1 || $type === 'dir') {
                if (isset($this->entries[$prefix]) && $this->entries[$prefix]['type'] !== 'dir') {
                    throw new RuntimeException('The archive uses the same path for a file and a directory.');
                }
                if (!isset($this->entries[$prefix])) {
                    $this->consumeNameBytes(strlen($prefix));
                    $this->entries[$prefix] = ['type' => 'dir', 'originalName' => null, 'size' => 0, 'mtime' => 0, 'crc' => null, 'encrypted' => false];
                    $this->checkIndexSize();
                }
            }
        }
        if ($type === 'dir') {
            if ($size !== 0) {
                throw new RuntimeException('The archive directory reports an invalid size.');
            }
            $this->entries[$path]['originalName'] = $name;
            return;
        }
        if (isset($this->entries[$path])) {
            throw new RuntimeException('The archive uses the same path for a file and a directory.');
        }
        if ($size < 0 || $size > $this->maxExpandedBytes - $this->totalSize || $compressedSize < 0 || $compressedSize > PHP_INT_MAX - $this->packedSize) {
            throw new RuntimeException('The archive exceeds the expanded size limit or reports an invalid size.');
        }
        $this->totalSize += $size;
        $this->packedSize += $compressedSize;
        $this->encrypted = $this->encrypted || $encrypted;
        $this->consumeNameBytes(strlen($path));
        $this->entries[$path] = ['type' => 'file', 'originalName' => $name, 'size' => $size, 'mtime' => $mtime, 'crc' => $crc, 'encrypted' => $encrypted];
        $this->fileCount++;
        $this->checkIndexSize();
    }

    public function result(string $format, ?int $compressedSize = null): array {
        if ($compressedSize !== null && $compressedSize < 0) {
            throw new RuntimeException('The archive reports an invalid compressed size.');
        }
        ksort($this->entries, SORT_STRING);
        return ['format' => $format, 'fileCount' => $this->fileCount, 'directoryCount' => count($this->entries) - $this->fileCount,
            'totalSize' => $this->totalSize, 'compressedSize' => $compressedSize ?? $this->packedSize,
            'encrypted' => $this->encrypted, 'entries' => $this->entries];
    }

    /** @return array{string,list<string>,list<string>} */
    public function normalizePath(string $name): array {
        if ($name === '' || strlen($name) > 4000 || str_contains($name, "\0") || preg_match('//u', $name) !== 1) {
            throw new RuntimeException('The archive contains an invalid or unsupported filename.');
        }
        $name = str_replace('\\', '/', $name);
        if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
            throw new RuntimeException('The archive contains an absolute path.');
        }
        $rawParts = [];
        foreach (explode('/', $name) as $part) {
            if ($part === '..') {
                throw new RuntimeException('The archive contains a parent-directory path.');
            }
            if ($part !== '' && $part !== '.') {
                $rawParts[] = $part;
            }
        }
        if ($rawParts === [] || count($rawParts) > $this->maxDepth) {
            throw new RuntimeException('The archive contains an empty or excessively deep path.');
        }
        $parts = [];
        foreach ($rawParts as $part) {
            $normalized = Normalizer::normalize($part, Normalizer::FORM_C);
            // Native FilenameValidator indexes PHP string bytes at offset250.
            // Apply its 250-byte limit after NFC, without truncating identity.
            if (!is_string($normalized) || strlen($normalized) > self::MAX_COMPONENT_BYTES) {
                throw new RuntimeException('The archive contains an invalid or excessively long filename.');
            }
            $parts[] = $normalized;
        }
        $path = implode('/', $parts);
        if (strlen($path) > 4000) {
            throw new RuntimeException('The normalized archive path exceeds the maximum byte length.');
        }
        return [$path, $rawParts, $parts];
    }

    private function consumeNameBytes(int $bytes): void {
        if ($bytes > $this->maxIndexNameBytes - $this->nameBytes) {
            throw new RuntimeException('The archive exceeds the index filename size limit.');
        }
        $this->nameBytes += $bytes;
    }

    private function checkIndexSize(): void {
        if (count($this->entries) > $this->maxEntries) {
            throw new RuntimeException('The archive contains too many indexed files and directories.');
        }
    }
}
