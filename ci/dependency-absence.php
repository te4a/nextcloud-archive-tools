<?php
declare(strict_types=1);

// Run with child-only disable_functions flags; never rename system binaries or
// change shared PHP configuration just to exercise optional capabilities.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$app = getenv('ARCHIVE_TOOLS_APP_PATH') ?: '/var/www/html/custom_apps/archive_tools';
spl_autoload_register(static function (string $class) use ($app): void {
    $prefix = 'OCA\\ArchiveTools\\Archive\\';
    if (str_starts_with($class, $prefix)) {
        $name = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $name)) { require_once $app . '/lib/Archive/' . $name . '.php'; }
    }
});

$mode = $argv[1] ?? '';
$checks = 0;
function dependencyCheck(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
    echo 'PASS ' . $message . PHP_EOL;
}

$files = [];
try {
    dependencyCheck(in_array($mode, ['process-disabled', 'links-disabled'], true), 'dependency test mode is explicit');
    dependencyCheck($mode === 'process-disabled' ? !function_exists('proc_open') : (!function_exists('link') && !function_exists('symlink')),
        'only this PHP child has the simulated missing capability');
    $reader = new OCA\ArchiveTools\Archive\ArchiveReader();
    $formats = $reader->supportedFormats();
    dependencyCheck(array_intersect(['7z', 'rar', 'tar.bz2', 'tar.xz'], $formats) === [], 'unavailable CLI archive formats are not advertised');
    dependencyCheck(in_array('zip', $formats, true) && in_array('tar', $formats, true) && in_array('tar.gz', $formats, true),
        'native ZIP and TAR capabilities remain available');

    $zipPath = tempnam(sys_get_temp_dir(), 'archive-tools-ci-deps-');
    if ($zipPath === false) { throw new RuntimeException('Could not reserve the owned ZIP fixture.'); }
    $files[] = $zipPath;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Could not create ZIP fixture.'); }
    $zip->addFromString('hello.txt', 'x');
    $zip->close();
    dependencyCheck($reader->inspect($zipPath)['fileCount'] === 1, 'ZIP inspect works without optional CLI capability');
    $stream = $reader->stream($zipPath, 'hello.txt');
    try { dependencyCheck(stream_get_contents($stream) === 'x', 'ZIP download bytes remain correct'); }
    finally { fclose($stream); }

    $header = str_repeat("\0", 512);
    foreach ([0 => 'hello.txt', 100 => "0000644\0", 108 => "0000000\0", 116 => "0000000\0",
        124 => "00000000001\0", 136 => "00000000000\0", 148 => '        ', 156 => '0', 257 => "ustar\0", 263 => '00'] as $offset => $value) {
        $header = substr_replace($header, $value, $offset, strlen($value));
    }
    $header = substr_replace($header, sprintf("%06o\0 ", array_sum(unpack('C*', $header))), 148, 8);
    $tarPath = tempnam(sys_get_temp_dir(), 'archive-tools-ci-deps-');
    if ($tarPath === false) { throw new RuntimeException('Could not reserve the owned TAR fixture.'); }
    $files[] = $tarPath;
    file_put_contents($tarPath, $header . 'x' . str_repeat("\0", 511 + 1024));
    dependencyCheck($reader->inspect($tarPath)['fileCount'] === 1, 'TAR inspect works without optional CLI capability');
    $stream = $reader->stream($tarPath, 'hello.txt');
    try { dependencyCheck(stream_get_contents($stream) === 'x', 'TAR download bytes remain correct'); }
    finally { fclose($stream); }
    echo 'All ' . $checks . ' optional dependency tests passed.' . PHP_EOL;
} finally {
    foreach ($files as $file) { if (is_file($file)) { unlink($file); } }
}
