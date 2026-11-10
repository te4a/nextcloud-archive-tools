<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OCA\ArchiveTools\Archive\ArchiveReader;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Lock\ILockingProvider;

final class ArchiveService {
    public function __construct(private IRootFolder $root, private ArchiveReader $reader, private MountRepository $mounts) {}

    public function node(string $owner, int $id): Node {
        foreach ($this->root->getUserFolder($owner)->getById($id) as $node) {
            if ($node->isReadable()) { return $node; }
        }
        throw new NotFoundException('File not found.');
    }

    public function source(string $owner, int $id): File {
        $node = $this->node($owner, $id);
        if (!$node instanceof File) { throw new \InvalidArgumentException('Select an archive file.'); }
        $this->checkBackgroundStorage($node);
        return $node;
    }

    public function destination(string $owner, int $id): Folder {
        $node = $this->node($owner, $id);
        if (!$node instanceof Folder) { throw new \InvalidArgumentException('Select a destination folder.'); }
        if (!$node->isCreatable()) { throw new NotPermittedException('You cannot extract files into this folder.'); }
        $this->checkBackgroundStorage($node);
        return $node;
    }

    public function checkBackgroundStorage(Node $node): void {
        if ($node->getMountPoint()->getMountProvider() === \OCA\ArchiveTools\Mount\MountProvider::class) {
            throw new \InvalidArgumentException('Processing an archive inside another mounted archive is not available. Extract the inner archive first.');
        }
        if ($node->getMountPoint()->getOption('authenticated', false)) {
            throw new \InvalidArgumentException('Background extraction is unavailable for storage that requires your login credentials.');
        }
    }

    public function localPath(File $source): string {
        $path = $source->getStorage()->getLocalFile($source->getInternalPath());
        if (!is_string($path) || $path === '') { throw new \RuntimeException('Unable to read the archive.'); }
        return $path;
    }

    /** Read after acquiring the source lock; its Node FileInfo may predate it. */
    public function currentEtag(File $source, ?string $expectedEtag = null): string {
        $entry = $source->getStorage()->getCache()->get($source->getInternalPath());
        if ($entry === false) { throw new NotFoundException('Archive not found.'); }
        $etag = (string)$entry['etag'];
        if ($expectedEtag !== null && !hash_equals($etag, $expectedEtag)) { throw new ArchiveChangedException(); }
        return $etag;
    }

    public function inspect(string $owner, int $sourceId, #[\SensitiveParameter] ?string $password = null): array {
        $source = $this->source($owner, $sourceId);
        $source->lock(ILockingProvider::LOCK_SHARED);
        $leases = [];
        try {
            $sourceEtag = $this->currentEtag($source);
            $leases = $this->mounts->beginSourceActivity($sourceId);
            $index = $this->reader->inspect($this->localPath($source), $password);
            if ($password !== null && $password !== '') { $this->probePassword($this->localPath($source), $index['entries'], $password); }
            $entries = [];
            foreach ($index['entries'] as $path => $entry) {
                $entries[] = ['path' => (string)$path, 'type' => $entry['type'], 'size' => $entry['size'], 'mtime' => $entry['mtime'], 'encrypted' => $entry['encrypted']];
            }
            return ['sourceId' => $sourceId, 'sourceName' => $source->getName(), 'parentId' => $source->getParent()->getId(), 'sourceEtag' => $sourceEtag,
                'format' => $index['format'], 'fileCount' => $index['fileCount'], 'directoryCount' => $index['directoryCount'],
                'totalSize' => $index['totalSize'], 'compressedSize' => $index['compressedSize'], 'encrypted' => $index['encrypted'], 'entries' => $entries];
        } finally {
            $this->mounts->endSourceActivity($leases);
            $source->unlock(ILockingProvider::LOCK_SHARED);
        }
    }

    public function probePassword(string $path, array $entries, #[\SensitiveParameter] ?string $password): void {
        foreach ($entries as $entry) {
            if ($entry['type'] === 'file' && $entry['encrypted']) {
                if ($password === null || $password === '') { throw new \InvalidArgumentException('This archive requires a password.'); }
                $stream = $this->reader->stream($path, $entry['originalName'], $password);
                try {
                    if (fread($stream, 1) === false) { throw new \RuntimeException('Unable to verify the archive password.'); }
                } finally { fclose($stream); }
                return;
            }
        }
    }

    /** Select complete entries, preserving directory structure and raw names. */
    public function select(array $index, array $members): array {
        if (count($members) > 10000) { throw new \InvalidArgumentException('Too many selected archive entries.'); }
        $selected = [];
        if ($members === []) { return $index['entries']; }
        foreach ($members as $member) {
            if (!is_string($member) || $member === '' || str_contains($member, '\\') || str_contains($member, "\0")) {
                throw new \InvalidArgumentException('Invalid archive selection.');
            }
            $member = rtrim($member, '/');
            if (!isset($index['entries'][$member])) { throw new \InvalidArgumentException('A selected entry is not present in the archive.'); }
            $selected[$member] = $index['entries'][$member];
            if ($index['entries'][$member]['type'] === 'dir') {
                foreach ($index['entries'] as $path => $entry) {
                    if (str_starts_with((string)$path, $member . '/')) { $selected[$path] = $entry; }
                }
            }
        }
        ksort($selected, SORT_STRING);
        return $selected;
    }

    public function folderName(string $name, File $source): string {
        if ($name === '') { $name = ArchiveReader::baseName($source->getName()) . ' (extracted)'; }
        $normalized = \Normalizer::normalize($name, \Normalizer::NFC);
        if (!is_string($normalized) || $normalized === '' || strlen($normalized) > 240 || $normalized === '.' || $normalized === '..'
            || preg_match('/[\x00-\x1f\/\\\\]/', $normalized)) {
            throw new \InvalidArgumentException('Invalid extraction folder name.');
        }
        return $normalized;
    }
}
