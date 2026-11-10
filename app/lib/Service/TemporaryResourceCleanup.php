<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

/** Nonrecursive recovery of expired decoder aliases and literal-name lists. */
final class TemporaryResourceCleanup {
    private const MAX_SCAN = 5000;
    private const MAX_DELETE = 200;
    private string $directory;
    private array $rootIdentity;

    public function __construct(?string $directory = null) {
        $resolved = realpath($directory ?? sys_get_temp_dir());
        if (!is_string($resolved) || !is_dir($resolved)) { throw new \RuntimeException('The archive temporary directory is unavailable.'); }
        $this->directory = $resolved === '/' ? '/' : rtrim($resolved, '/');
        $this->rootIdentity = lstat($this->directory);
    }

    public function inspect(): array { return $this->scan(false); }
    public function cleanup(): array { return $this->scan(true); }

    private function scan(bool $remove): array {
        $result = ['expiredInputs' => 0, 'expiredNameLists' => 0, 'retainedFreshInputs' => 0,
            'retainedFreshNameLists' => 0, 'removedInputs' => 0, 'removedNameLists' => 0,
            'skipped' => 0, 'scanLimitReached' => false, 'deletionLimitReached' => false,
            'scanFailed' => false, 'ownershipVerified' => function_exists('posix_geteuid')];
        if (!$result['ownershipVerified']) { return $result; }
        $owner = posix_geteuid();
        $cutoff = time() - 3600;
        $scanned = 0;
        try { $entries = new \DirectoryIterator($this->directory); }
        catch (\UnexpectedValueException) { $result['scanFailed'] = true; return $result; }
        foreach ($entries as $entry) {
            if (++$scanned > self::MAX_SCAN) { $result['scanLimitReached'] = true; break; }
            if ($result['removedInputs'] + $result['removedNameLists'] >= self::MAX_DELETE) { $result['deletionLimitReached'] = true; break; }
            $name = $entry->getFilename();
            $input = preg_match('/^archive-tools-input-[a-f0-9]{32}$/D', $name) === 1;
            $names = preg_match('/^archive-tools-names-[A-Za-z0-9]{6}$/D', $name) === 1;
            if (!$input && !$names) { continue; }
            $path = $this->directory . '/' . $name;
            clearstatcache(true, $path);
            $info = @lstat($path);
            if (!is_array($info) || $info['uid'] !== $owner
                || ($info['mode'] & 0170000) !== ($input ? 0040000 : 0100000)
                || ($info['mode'] & 07777) !== ($input ? 0700 : 0600)
                || ($names && $info['nlink'] !== 1)) { ++$result['skipped']; continue; }
            if ($input && !$this->validAlias($path)) { ++$result['skipped']; continue; }
            $kind = $input ? 'Inputs' : 'NameLists';
            // A future timestamp is retained too. There is no caller-controlled
            // clock parameter that could turn fresh resources into expired ones.
            if ($info['mtime'] >= $cutoff) { ++$result['retainedFresh' . $kind]; continue; }
            ++$result['expired' . $kind];
            if (!$remove) { continue; }
            if (!$this->unchanged($this->directory, $this->rootIdentity, true)
                || !$this->unchanged($path, $info)
                || ($input && !$this->validAlias($path))) { ++$result['skipped']; continue; }
            if ($input) {
                // This may itself be a symlink/hardlink; unlink only the literal
                // alias. Never resolve or remove the source archive it points to.
                if (@unlink($path . '/archive.bin') && @rmdir($path)) { ++$result['removedInputs']; }
                else { ++$result['skipped']; }
            } elseif (@unlink($path)) { ++$result['removedNameLists']; }
            else { ++$result['skipped']; }
        }
        return $result;
    }

    private function validAlias(string $directory): bool {
        try {
            $children = [];
            foreach (new \DirectoryIterator($directory) as $child) {
                if ($child->isDot()) { continue; }
                $children[] = $child->getFilename();
                if (count($children) > 1) { return false; }
            }
            if ($children !== ['archive.bin']) { return false; }
            $alias = @lstat($directory . '/archive.bin');
            return is_array($alias) && in_array($alias['mode'] & 0170000, [0100000, 0120000], true);
        } catch (\UnexpectedValueException) { return false; }
    }

    private function unchanged(string $path, array $expected, bool $identityOnly = false): bool {
        clearstatcache(true, $path);
        $fresh = @lstat($path);
        if (!is_array($fresh)) { return false; }
        foreach ($identityOnly ? ['dev', 'ino', 'mode', 'uid'] : ['dev', 'ino', 'mode', 'uid', 'mtime', 'nlink'] as $key) {
            if ($fresh[$key] !== $expected[$key]) { return false; }
        }
        return true;
    }
}
