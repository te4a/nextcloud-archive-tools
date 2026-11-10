<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

/** Expose exactly one authorized input, without discoverable sibling volumes. */
final class IsolatedArchiveInput {
    private string $directory;
    private string $alias;
    private bool $closed = false;

    public function __construct(string $source) {
        $resolved = realpath($source);
        if (!is_string($resolved) || !is_file($resolved) || !is_readable($resolved)) {
            throw new \RuntimeException('The archive is not readable.');
        }
        $this->directory = sys_get_temp_dir() . '/archive-tools-input-' . bin2hex(random_bytes(16));
        $this->alias = $this->directory . '/archive.bin';
        if (!mkdir($this->directory, 0700)) { throw new \RuntimeException('The archive input could not be isolated.'); }
        if (!chmod($this->directory, 0700)) {
            @rmdir($this->directory);
            throw new \RuntimeException('The archive input could not be isolated.');
        }
        // Resolve source symlinks before linking. A hardlink needs no copy;
        // across filesystems the symlink still keeps the decoder's literal
        // folder prefix inside this private, otherwise empty directory.
        if (function_exists('link') && @link($resolved, $this->alias)) { return; }
        if (function_exists('symlink') && @symlink($resolved, $this->alias)) { return; }
        @rmdir($this->directory);
        throw new \RuntimeException('The archive input could not be isolated.');
    }

    public function path(): string {
        if ($this->closed) { throw new \RuntimeException('The archive input could not be isolated.'); }
        return $this->alias;
    }

    public function close(): void {
        if ($this->closed) { return; }
        $this->closed = true;
        // Remove only our own alias and directory, never source or other files.
        if (isset($this->alias)) { @unlink($this->alias); }
        if (isset($this->directory)) { @rmdir($this->directory); }
    }

    /** Recover only our orphaned inputs after a worker crash, never recurse. */
    public static function cleanupExpired(?int $now = null): int {
        $now ??= time();
        $cutoff = $now - 3600; // Normal decoder operations are limited to 300s.
        $owner = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $removed = 0;
        $scanned = 0;
        $temporaryRoot = rtrim(sys_get_temp_dir(), '/');
        foreach (new \DirectoryIterator($temporaryRoot) as $entry) {
            if (++$scanned > 5000 || $removed >= 200) { break; }
            $name = $entry->getFilename();
            if (!preg_match('/^archive-tools-input-[a-f0-9]{32}$/D', $name)) { continue; }
            $directory = $temporaryRoot . '/' . $name;
            $info = @lstat($directory);
            if (!is_array($info) || ($info['mode'] & 0170000) !== 0040000 || ($info['mode'] & 07777) !== 0700
                || $info['mtime'] >= $cutoff || ($owner !== null && $info['uid'] !== $owner)) { continue; }
            try {
                $valid = true;
                $count = 0;
                foreach (new \DirectoryIterator($directory) as $child) {
                    if ($child->isDot()) { continue; }
                    if (++$count > 1 || $child->getFilename() !== 'archive.bin') { $valid = false; break; }
                }
                if (!$valid || $count !== 1) { continue; }
                $alias = $directory . '/archive.bin';
                $linkInfo = @lstat($alias);
                if (!is_array($linkInfo) || !in_array($linkInfo['mode'] & 0170000, [0100000, 0120000], true)) { continue; }
                // Recheck directory identity before unlinking a literal child;
                // lstat never follows a directory or archive symlink target.
                clearstatcache(true, $directory);
                $fresh = @lstat($directory);
                if (!is_array($fresh) || $fresh['dev'] !== $info['dev'] || $fresh['ino'] !== $info['ino']
                    || ($fresh['mode'] & 0170000) !== 0040000 || ($fresh['mode'] & 07777) !== 0700
                    || $fresh['mtime'] >= $cutoff || ($owner !== null && $fresh['uid'] !== $owner)) { continue; }
                if (@unlink($alias) && @rmdir($directory)) { ++$removed; }
            } catch (\UnexpectedValueException) {
                // A concurrent cleanup or permission change is not evidence
                // that any other path should be removed.
            }
        }
        return $removed;
    }

    public function __destruct() { $this->close(); }
}
