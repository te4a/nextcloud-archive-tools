<?php

declare(strict_types=1);

use OCA\ArchiveTools\Archive\ArchiveReader;
use OCA\ArchiveTools\Archive\ArchivePasswordRequiredException;

$appPath = getenv('ARCHIVE_TOOLS_APP_PATH') ?: dirname(__DIR__) . '/app';
foreach (['EntryIndexBuilder', 'ZipReader', 'TarReader', 'ArchivePasswordRequiredException', 'IsolatedArchiveInput', 'ProcessStream', 'SevenZipReader', 'ArchiveReader'] as $class) {
    require_once $appPath . '/lib/Archive/' . $class . '.php';
}
$createdFiles = [];
$formats = isset($argv[1]) ? [$argv[1]] : ['7z', '7z-encrypted', 'rar', 'rar5'];
try {
    foreach ($formats as $format) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/format-fixtures.php', $format], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Fixture helper could not be started.'); }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException('Fixture helper failed: ' . $error); }
        $fixture = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $path = tempnam(sys_get_temp_dir(), 'archive-format-');
        $createdFiles[] = $path;
        file_put_contents($path, base64_decode($fixture['base64'], true));
        $reader = new ArchiveReader();
        if (isset($fixture['password'])) {
            $required = false;
            try { $reader->inspect($path); } catch (ArchivePasswordRequiredException) { $required = true; }
            if (!$required) { throw new RuntimeException('Encrypted 7-Zip headers must request the password.'); }
        }
        $index = $reader->inspect($path, $fixture['password'] ?? null);
        if ($index['fileCount'] !== count($fixture['files'])) { throw new RuntimeException('Fixture entry count differs from the independent manifest.'); }
        foreach ($fixture['files'] as $name => $expected) {
            $stream = $reader->stream($path, $index['entries'][$name]['originalName'], $fixture['password'] ?? null);
            try {
                if (base64_encode(stream_get_contents($stream)) !== $expected) { throw new RuntimeException('Fixture member content differs from the independent manifest.'); }
            } finally { fclose($stream); }
        }
        echo 'PASS ' . $format . " inspect and complete member streams\n";
    }
    echo 'All ' . count($formats) . " additional format reader tests passed.\n";
} finally {
    foreach ($createdFiles as $path) { if (is_string($path) && is_file($path)) { unlink($path); } }
}
