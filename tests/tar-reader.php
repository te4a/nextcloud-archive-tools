<?php

declare(strict_types=1);

use OCA\ArchiveTools\Archive\ArchiveReader;
use OCA\ArchiveTools\Archive\TarReader;

$appPath = getenv('ARCHIVE_TOOLS_APP_PATH') ?: dirname(__DIR__) . '/app';
foreach (['EntryIndexBuilder', 'ZipReader', 'TarReader', 'IsolatedArchiveInput', 'ProcessStream', 'SevenZipReader', 'ArchiveReader'] as $class) {
    require_once $appPath . '/lib/Archive/' . $class . '.php';
}
if (!extension_loaded('intl') || !extension_loaded('zlib')) {
    fwrite(STDERR, "The TAR tests require PHP intl and zlib.\n");
    exit(1);
}
$workDirectory = sys_get_temp_dir() . '/archive-tools-tar-' . bin2hex(random_bytes(8));
if (!mkdir($workDirectory, 0700)) { throw new RuntimeException('Unable to create the test directory.'); }
$createdFiles = [];
$passed = 0;

function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

function rejects(callable $operation, string $description): void {
    try { $operation(); } catch (RuntimeException) { return; }
    throw new RuntimeException('Expected rejection: ' . $description);
}

function test(string $name, callable $operation): void {
    global $passed;
    $operation();
    $passed++;
    echo 'PASS ' . $name . "\n";
}

function tarHeader(string $name, int $size, string $type = '0', string $prefix = '', string $link = ''): string {
    $header = str_pad($name, 100, "\0") . "0000644\0" . "0000000\0" . "0000000\0"
        . sprintf('%011o', $size) . "\0" . sprintf('%011o', 1700000000) . "\0" . str_repeat(' ', 8)
        . $type . str_pad($link, 100, "\0") . "ustar\0" . '00' . str_repeat("\0", 80)
        . str_pad($prefix, 155, "\0") . str_repeat("\0", 12);
    check(strlen($header) === 512, 'TAR fixture header length');
    return substr_replace($header, sprintf('%06o', array_sum(unpack('C*', $header))) . "\0 ", 148, 8);
}

function tarMember(string $name, string $data = '', string $type = '0', string $prefix = '', string $link = ''): string {
    return tarHeader($name, strlen($data), $type, $prefix, $link) . $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
}

function paxRecord(string $key, string $value): string {
    $tail = ' ' . $key . '=' . $value . "\n";
    $length = strlen($tail) + 1;
    do { $previous = $length; $length = strlen((string)$length) + strlen($tail); } while ($previous !== $length);
    return $length . $tail;
}

function tarFile(string $members, bool $gzip = false, bool $terminated = true): string {
    global $workDirectory, $createdFiles;
    // Intentionally extensionless: remote Nextcloud local copies need magic detection.
    $path = $workDirectory . '/' . count($createdFiles);
    $createdFiles[] = $path;
    $contents = $members . ($terminated ? str_repeat("\0", 1024) : '');
    file_put_contents($path, $gzip ? gzencode($contents) : $contents);
    return $path;
}

try {
    test('TAR index, root entry and inferred directories', function (): void {
        $path = tarFile(tarMember('./', '', '5') . tarMember('./nested/file.txt', 'alpha') . tarMember('empty/', '', '5'));
        $index = (new TarReader())->inspect($path);
        check($index['format'] === 'tar' && $index['fileCount'] === 1 && $index['directoryCount'] === 2, 'Index counts');
        check($index['totalSize'] === 5 && $index['compressedSize'] === filesize($path), 'Index sizes');
        check($index['entries']['nested/file.txt']['originalName'] === './nested/file.txt', 'Preserve raw member name');
        check($index['entries']['nested/file.txt']['crc'] === null && !$index['encrypted'], 'TAR integrity metadata');
    });

    test('TAR member stream owns its source and excludes padding and other members', function (): void {
        $payload = str_repeat("binary\0payload\xff\n", 3000);
        $path = tarFile(tarMember('first.txt', 'first') . tarMember('data.bin', $payload) . tarMember('last.txt', 'last'));
        $reader = new TarReader();
        $stream = $reader->stream($path, 'data.bin');
        unset($reader);
        check(fstat($stream)['size'] === strlen($payload), 'Member stream size');
        check(fseek($stream, 1) === -1, 'Member stream is non-seekable');
        check(stream_get_contents($stream) === $payload && feof($stream), 'Member bytes and EOF');
        fclose($stream);
    });

    test('empty TAR and empty file', function (): void {
        check((new TarReader())->inspect(tarFile(''))['entries'] === [], 'Empty archive');
        $path = tarFile(tarMember('empty', ''));
        $stream = (new TarReader())->stream($path, 'empty');
        check(stream_get_contents($stream) === '' && feof($stream), 'Empty member');
        fclose($stream);
    });

    test('batch streams read archive order, selections and empty directories in one pass', function (): void {
        $path = tarFile(tarMember('z.txt', 'last-name-first') . tarMember('empty/', '', '5') . tarMember('a.txt', 'first-name-last'), true);
        $reader = new ArchiveReader();
        $index = $reader->inspect($path);
        $seen = [];
        foreach ($reader->streams($path, $index['entries']) as $name => $stream) {
            $seen[$name] = stream_get_contents($stream);
            fclose($stream);
        }
        check(array_keys($seen) === ['z.txt', 'a.txt'] && $seen['a.txt'] === 'first-name-last', 'Single-pass archive order');
        $seen = [];
        foreach ($reader->streams($path, ['a.txt' => $index['entries']['a.txt']]) as $name => $stream) {
            $seen[$name] = stream_get_contents($stream);
            fclose($stream);
        }
        check($seen === ['a.txt' => 'first-name-last'], 'Skip unselected payloads');
        check(isset($index['entries']['empty']) && iterator_to_array($reader->streams($path, ['empty' => $index['entries']['empty']])) === [], 'Directory-only selections preserve the index and yield no file streams');
    });

    test('batch streams balance unread members and close on cancellation', function (): void {
        $path = tarFile(tarMember('first.bin', str_repeat('x', 30000)) . tarMember('second.txt', 'second'), true);
        $reader = new ArchiveReader();
        $index = $reader->inspect($path);
        $seen = [];
        foreach ($reader->streams($path, $index['entries']) as $name => $stream) {
            $seen[$name] = fread($stream, 1);
            fclose($stream);
        }
        check($seen === ['first.bin' => 'x', 'second.txt' => 's'], 'Skip only the actual unread payload after PHP buffering');
        $generator = $reader->streams($path, $index['entries']);
        $generator->rewind();
        $stream = $generator->current();
        check(is_resource($stream), 'Batch begins with an owned member');
        unset($generator);
        gc_collect_cycles();
        check(!is_resource($stream), 'Destroying a cancelled generator closes its member stream');
    });

    test('batch streams validate the complete archive and missing selections', function (): void {
        $path = tarFile(tarMember('safe', 'payload'));
        $reader = new ArchiveReader();
        $index = $reader->inspect($path);
        file_put_contents($path, 'corrupt suffix', FILE_APPEND);
        rejects(function () use ($reader, $path, $index): void {
            foreach ($reader->streams($path, $index['entries']) as $stream) { stream_get_contents($stream); fclose($stream); }
        }, 'Complete trailer checked after the last selected member');
        rejects(function () use ($reader): void {
            iterator_to_array($reader->streams(tarFile(''), ['missing' => ['type' => 'file', 'originalName' => 'missing', 'size' => 0]]));
        }, 'Missing batch selection');
    });

    test('gzip TAR and format dispatch work without file extensions', function (): void {
        $payload = str_repeat('gzip member ', 1000);
        $path = tarFile(tarMember('gzip.txt', $payload), true);
        $reader = new ArchiveReader();
        check($reader->detect($path) === 'tar.gz' && $reader->inspect($path)['format'] === 'tar.gz', 'Gzip format');
        $stream = $reader->stream($path, 'gzip.txt');
        check(stream_get_contents($stream) === $payload, 'Gzip content');
        fclose($stream);
        check($reader->detect(tarFile(tarMember('plain', 'tar'))) === 'tar', 'Plain TAR detection');
    });

    test('USTAR prefixes and GNU long filenames', function (): void {
        $longName = str_repeat('nested/', 20) . 'long-file.txt';
        $path = tarFile(tarMember('file.txt', 'prefix', '0', 'directory/prefix')
            . tarMember('././@LongLink', $longName . "\0", 'L') . tarMember('placeholder', 'long'));
        $index = (new TarReader())->inspect($path);
        check(isset($index['entries']['directory/prefix/file.txt'], $index['entries'][$longName]), 'Extended names');
        $stream = (new TarReader())->stream($path, $longName);
        check(stream_get_contents($stream) === 'long', 'Read GNU long member');
        fclose($stream);
    });

    test('PAX path, size, timestamps and global attributes', function (): void {
        $rawName = "Cafe\u{0301}/data.txt";
        $metadata = paxRecord('path', $rawName) . paxRecord('size', '4') . paxRecord('mtime', '1700000001.25');
        $path = tarFile(tarMember('global', paxRecord('mtime', '1600000000'), 'g')
            . tarMember('pax', $metadata, 'x') . tarMember('short', 'data') . tarMember('after', 'global'));
        $index = (new TarReader())->inspect($path);
        $entry = $index['entries']["Caf\u{00e9}/data.txt"];
        check($entry['originalName'] === $rawName && $entry['mtime'] === 1700000001, 'PAX and NFC');
        check($index['entries']['after']['mtime'] === 1600000000, 'Global metadata persists');
        $stream = (new TarReader())->stream($path, $entry['originalName']);
        check(stream_get_contents($stream) === 'data', 'Read raw PAX path');
        fclose($stream);
    });

    test('unsafe paths are rejected for all TAR names', function (): void {
        foreach (['../outside', '/absolute', 'C:/absolute', 'folder/../../outside', '..\\outside'] as $name) {
            rejects(fn () => (new TarReader())->inspect(tarFile(tarMember($name, 'unsafe'))), $name);
        }
        rejects(fn () => (new TarReader())->inspect(tarFile(tarMember('pax', paxRecord('path', '../outside'), 'x') . tarMember('safe', 'x'))), 'Unsafe PAX path');
    });

    test('PAX names obey the native byte limit after NFC normalization', function (): void {
        $reader = new TarReader();
        $archive = static fn (string $name): string => tarFile(tarMember('pax', paxRecord('path', $name), 'x') . tarMember('placeholder', 'payload'));
        $ascii = str_repeat('a', 250);
        check(isset($reader->inspect($archive($ascii))['entries'][$ascii]), 'Accept 250 ASCII characters');
        rejects(fn () => $reader->inspect($archive(str_repeat('a', 251))), 'Reject 251 ASCII characters before native cache insertion');
        $unicode = str_repeat('я', 125);
        check(isset($reader->inspect($archive($unicode))['entries'][$unicode]), 'Accept a valid 250-byte non-ASCII component');
        rejects(fn () => $reader->inspect($archive($unicode . 'a')), 'Reject 251 UTF-8 bytes despite fewer than 250 characters');
        $nfd = str_repeat('a', 248) . "e\u{0301}";
        $nfc = str_repeat('a', 248) . "\u{00E9}";
        check(isset($reader->inspect($archive($nfd))['entries'][$nfc]), 'Accept raw 251 bytes normalized to 250 bytes');
    });

    test('symlinks, hardlinks, devices, FIFO and sparse entries are rejected', function (): void {
        foreach (['1', '2', '3', '4', '6', 'S', 'K'] as $type) {
            rejects(fn () => (new TarReader())->inspect(tarFile(tarMember('special', '', $type))), 'Special type ' . $type);
        }
        rejects(fn () => (new TarReader())->inspect(tarFile(tarMember('pax', paxRecord('GNU.sparse.map', '0,1'), 'x') . tarMember('sparse', 'x'))), 'PAX sparse');
    });

    test('duplicates, normalized names and file-directory collisions fail', function (): void {
        foreach ([tarMember('a', 'one') . tarMember('a', 'two'), tarMember('a', 'one') . tarMember('./a', 'two'),
            tarMember("Cafe\u{0301}/one", 'one') . tarMember("Caf\u{00e9}/two", 'two'),
            tarMember('parent', 'file') . tarMember('parent/child', 'child'),
            tarMember('parent/child', 'child') . tarMember('parent', 'file')] as $members) {
            rejects(fn () => (new TarReader())->inspect(tarFile($members)), 'Ambiguous names');
        }
    });

    test('entry count, inferred directories, expanded bytes, depth and names are bounded', function (): void {
        rejects(fn () => (new TarReader(maxEntries: 1))->inspect(tarFile(tarMember('one', '1') . tarMember('two', '2'))), 'Entry count');
        rejects(fn () => (new TarReader(maxEntries: 1))->inspect(tarFile(tarMember('parent/child', 'x'))), 'Inferred dirs');
        rejects(fn () => (new TarReader(maxExpandedBytes: 1))->inspect(tarFile(tarMember('big', 'xx'))), 'Expanded bytes');
        rejects(fn () => (new TarReader(maxDepth: 1))->inspect(tarFile(tarMember('deep/file', 'x'))), 'Depth');
        rejects(fn () => (new TarReader(maxIndexNameBytes: 5))->inspect(tarFile(tarMember('name', 'x'))), 'Name budget');
    });

    test('metadata and longname records are bounded and must be complete', function (): void {
        rejects(fn () => (new TarReader())->inspect(tarFile(tarMember('pax', str_repeat('x', 65537), 'x'))), 'Metadata size');
        rejects(fn () => (new TarReader())->inspect(tarFile(tarMember('pax', '999 path=short\n', 'x') . tarMember('next', 'x'))), 'Broken PAX length');
        rejects(fn () => (new TarReader())->inspect(tarFile(tarMember('pax', paxRecord('path', 'one') . paxRecord('path', 'two'), 'x') . tarMember('next', 'x'))), 'Duplicate PAX key');
        rejects(fn () => (new TarReader())->inspect(tarFile(tarMember('long', 'unterminated', 'L') . tarMember('next', 'x'))), 'Unterminated GNU longname');
        rejects(fn () => (new TarReader())->inspect(tarFile(tarMember('long', "orphan\0", 'L'))), 'Orphan metadata');
    });

    test('invalid checksums, truncated headers and payloads fail explicitly', function (): void {
        $member = tarMember('file.txt', 'payload');
        $member[0] = 'X';
        rejects(fn () => (new TarReader())->inspect(tarFile($member)), 'Header checksum');
        rejects(fn () => (new TarReader())->inspect(tarFile('short', false, false)), 'Truncated header');
        $path = tarFile(tarHeader('truncated', 10) . 'x', false, false);
        rejects(fn () => (new TarReader())->inspect($path), 'Truncated payload index');
        rejects(function () use ($path): void {
            $stream = (new TarReader())->stream($path, 'truncated');
            try { stream_get_contents($stream); } finally { fclose($stream); }
        }, 'Truncated payload stream');
    });

    test('end marker, nonzero suffix and excessive padding are rejected', function (): void {
        rejects(fn () => (new TarReader())->inspect(tarFile(tarMember('file', 'x'), false, false)), 'Missing terminator');
        $path = tarFile(tarMember('file', 'x'));
        file_put_contents($path, 'unexpected', FILE_APPEND);
        rejects(fn () => (new TarReader())->inspect($path), 'Nonzero trailer');
        $path = tarFile('');
        file_put_contents($path, str_repeat("\0", 1048577), FILE_APPEND);
        rejects(fn () => (new TarReader())->inspect($path), 'Padding budget');
    });

    test('gzip corruption and unrelated compressed files are rejected', function (): void {
        $path = tarFile(tarMember('file', 'payload'), true);
        $contents = file_get_contents($path);
        $contents[strlen($contents) - 8] = chr(ord($contents[strlen($contents) - 8]) ^ 1);
        file_put_contents($path, $contents);
        rejects(fn () => (new TarReader())->inspect($path), 'Gzip CRC');
        $path = tarFile(tarMember('file', 'payload'), true);
        file_put_contents($path, substr(file_get_contents($path), 0, -8));
        rejects(fn () => (new TarReader())->inspect($path), 'Truncated gzip footer');
        rejects(fn () => (new ArchiveReader())->inspect(tarFile('not a TAR', true, false)), 'Single-file gzip is not TAR');
    });

    test('missing members, directory streams and unsupported bytes fail', function (): void {
        $path = tarFile(tarMember('directory/', '', '5') . tarMember('present', 'x'));
        rejects(fn () => (new TarReader())->stream($path, 'missing'), 'Missing member');
        rejects(fn () => (new TarReader())->stream($path, 'directory/'), 'Directory stream');
        rejects(fn () => (new ArchiveReader())->inspect(tarFile('unsupported', false, false)), 'Unknown format');
    });

    echo 'All ' . $passed . " TAR reader tests passed.\n";
} finally {
    foreach ($createdFiles as $path) { if (is_file($path)) { unlink($path); } }
    if (is_dir($workDirectory)) { rmdir($workDirectory); }
}
