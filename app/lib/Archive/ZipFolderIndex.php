<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

/** Immutable ZIP metadata keyed by parent, with independently decoded folders. */
final class ZipFolderIndex {
    public const MAX_ENTRIES = 120000;
    public const MAX_NAME_BYTES = 33554432;
    private const MAX_CHILDREN = 10000;
    private const MAX_FOLDER_BYTES = 8388608;

    private ?string $previousFolder = null;
    private array $previousChildren = [];

    public function __construct(private readonly array $folders) {}

    public static function pack(array &$index, OperationBudget $budget): void {
        $entries = $index['entries'];
        unset($index['entries']);
        $groups = [];
        $sizes = [];
        // inspect() returns lexicographically sorted paths. Every descendant
        // sorts after its parent, so directory totals can accumulate in reverse.
        $paths = array_keys($entries);
        while ($paths !== []) {
            $path = (string)array_pop($paths);
            $entry = $entries[$path];
            unset($entries[$path]);
            $parent = self::parent($path);
            if ($entry['type'] === 'dir') {
                $entry['size'] = $sizes[$path] ?? 0;
            }
            $sizes[$parent] = ($sizes[$parent] ?? 0) + $entry['size'];
            $groups[$parent][basename($path)] = $entry;
            if (count($groups[$parent]) > self::MAX_CHILDREN) {
                throw new ZipIndexLimitException('The ZIP archive contains too many indexed files and directories.');
            }
            if (count($paths) % 256 === 0) {
                $budget->check();
            }
        }
        $index['folders'] = [];
        foreach ($groups as $path => $children) {
            $budget->check();
            $json = json_encode($children, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (strlen($json) > self::MAX_FOLDER_BYTES) {
                throw new ZipIndexLimitException('The ZIP archive exceeds the index filename size limit.');
            }
            $packed = gzdeflate($json, 1);
            if ($packed === false) {
                throw new \RuntimeException('The archive index could not be initialized.');
            }
            $index['folders'][$path] = base64_encode($packed);
            unset($groups[$path]);
        }
    }

    public function children(string $path): array {
        if ($this->previousFolder === $path) {
            return $this->previousChildren;
        }
        $encoded = $this->folders[$path] ?? null;
        if ($encoded === null) {
            $children = [];
        } else {
            $payload = base64_decode($encoded, true);
            $json = $payload === false ? false : gzinflate($payload, self::MAX_FOLDER_BYTES);
            if ($json === false) {
                throw new \RuntimeException('The archive index is invalid.');
            }
            $children = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }
        $this->previousFolder = $path;
        return $this->previousChildren = $children;
    }

    public function entry(string $path): ?array {
        return $this->children(self::parent($path))[basename($path)] ?? null;
    }

    private static function parent(string $path): string {
        $slash = strrpos($path, '/');
        return $slash === false ? '' : substr($path, 0, $slash);
    }
}
