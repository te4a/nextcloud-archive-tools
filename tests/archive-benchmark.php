<?php

declare(strict_types=1);

use OCA\ArchiveTools\Archive\ArchiveReader;

$appPath = getenv('ARCHIVE_TOOLS_APP_PATH') ?: dirname(__DIR__) . '/app';
foreach (['EntryIndexBuilder', 'ZipReader', 'TarReader', 'ArchivePasswordRequiredException', 'IsolatedArchiveInput', 'ProcessStream', 'SevenZipReader', 'ArchiveReader'] as $class) {
    require_once $appPath . '/lib/Archive/' . $class . '.php';
}
$work = sys_get_temp_dir() . '/archive-tools-benchmark-' . bin2hex(random_bytes(8));
if (!mkdir($work, 0700) || !mkdir($work . '/many', 0700)) { throw new RuntimeException('Benchmark directory could not be created.'); }
$created = [];
$binary = null;
foreach (['/usr/bin/7zz', '/usr/local/bin/7zz', '/usr/bin/7z', '/usr/local/bin/7z'] as $candidate) {
    if (is_executable($candidate)) { $binary = $candidate; break; }
}
if ($binary === null) { throw new RuntimeException('7-Zip is required for the benchmark.'); }

function benchmarkHeader(string $name, int $size): string {
    $header = str_pad($name, 100, "\0") . "0000644\0" . "0000000\0" . "0000000\0" . sprintf('%011o', $size) . "\0"
        . sprintf('%011o', 1700000000) . "\0" . str_repeat(' ', 8) . '0' . str_repeat("\0", 100) . "ustar\0" . '00' . str_repeat("\0", 247);
    return substr_replace($header, sprintf('%06o', array_sum(unpack('C*', $header))) . "\0 ", 148, 8);
}

function benchmarkCommand(array $command, string $cwd): void {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, ['LC_ALL' => 'C.UTF-8', 'LANG' => 'C.UTF-8']);
    if (!is_resource($process)) { throw new RuntimeException('Benchmark archiver could not be started.'); }
    fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Benchmark fixture creation failed.'); }
}

function measureArchive(string $name, string $path, int $expectedFiles, int $expectedBytes): array {
    gc_collect_cycles();
    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);
    $start = hrtime(true);
    $reader = new ArchiveReader();
    try { $index = $reader->inspect($path); } catch (RuntimeException $error) {
        return ['case' => $name, 'error' => $error->getMessage(), 'indexSeconds' => round((hrtime(true) - $start) / 1e9, 4),
            'indexPeakAdditionalBytes' => max(0, memory_get_peak_usage(true) - $baseline)];
    }
    if ($index['fileCount'] !== $expectedFiles || $index['totalSize'] !== $expectedBytes) { throw new RuntimeException('Benchmark index differs from the fixture.'); }
    $result = ['case' => $name, 'format' => $index['format'], 'files' => $index['fileCount'], 'expandedBytes' => $index['totalSize'],
        'archiveBytes' => filesize($path), 'indexSeconds' => round((hrtime(true) - $start) / 1e9, 4),
        'indexPeakAdditionalBytes' => max(0, memory_get_peak_usage(true) - $baseline), 'processPeakBytes' => memory_get_peak_usage(true)];
    if ($expectedBytes > 0) {
        memory_reset_peak_usage();
        $readStart = hrtime(true);
        $readBytes = 0;
        foreach ($reader->streams($path, $index['entries']) as $stream) {
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 1048576);
                    if ($chunk === false || ($chunk === '' && !feof($stream))) { throw new RuntimeException('Benchmark payload could not be read.'); }
                    $readBytes += strlen($chunk);
                }
            } finally { fclose($stream); }
        }
        if ($readBytes !== $expectedBytes) { throw new RuntimeException('Benchmark payload size differs from the fixture.'); }
        $result['batchReadSeconds'] = round((hrtime(true) - $readStart) / 1e9, 4);
        $result['batchReadPeakAdditionalBytes'] = max(0, memory_get_peak_usage(true) - $baseline);
    }
    unset($index, $reader);
    return $result;
}

try {
    $manyTar = $work . '/many.tar.gz'; $created[] = $manyTar;
    $gzip = gzopen($manyTar, 'wb6');
    for ($i = 0; $i < 10000; $i++) {
        $name = sprintf('file-%04d.empty', $i);
        gzwrite($gzip, benchmarkHeader($name, 0));
        $file = $work . '/many/' . $name; $created[] = $file; touch($file);
    }
    gzwrite($gzip, str_repeat("\0", 1024)); gzclose($gzip);
    $manySeven = $work . '/many.7z'; $created[] = $manySeven;
    benchmarkCommand([$binary, 'a', '-t7z', '-bd', '-bso0', '-bse2', '--', $manySeven, '*.empty'], $work . '/many');

    $payloadPath = $work . '/payload.bin'; $created[] = $payloadPath;
    $largeTar = $work . '/large.tar.gz'; $created[] = $largeTar;
    $size = 100 * 1024 * 1024;
    $payload = fopen($payloadPath, 'wb'); $gzip = gzopen($largeTar, 'wb6');
    gzwrite($gzip, benchmarkHeader('payload.bin', $size));
    $chunk = str_repeat('archive-tools-benchmark-payload!', 33826);
    $remaining = $size;
    while ($remaining > 0) {
        $part = substr($chunk, 0, min($remaining, strlen($chunk)));
        fwrite($payload, $part); gzwrite($gzip, $part); $remaining -= strlen($part);
    }
    fclose($payload); unset($chunk, $part);
    gzwrite($gzip, str_repeat("\0", 1024)); gzclose($gzip);
    $largeSeven = $work . '/large.7z'; $created[] = $largeSeven;
    benchmarkCommand([$binary, 'a', '-t7z', '-bd', '-bso0', '-bse2', '--', $largeSeven, 'payload.bin'], $work);

    $cases = [measureArchive('10000-empty-tar-gzip', $manyTar, 10000, 0), measureArchive('10000-empty-7z', $manySeven, 10000, 0),
        measureArchive('100MiB-tar-gzip', $largeTar, 1, $size), measureArchive('100MiB-7z', $largeSeven, 1, $size)];
    echo json_encode(['php' => PHP_VERSION, 'memoryLimit' => ini_get('memory_limit'), 'cases' => $cases], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    foreach (array_reverse($created) as $path) { if (is_file($path)) { unlink($path); } }
    if (is_dir($work . '/many')) { rmdir($work . '/many'); }
    if (is_dir($work)) { rmdir($work); }
}
