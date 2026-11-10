<?php

declare(strict_types=1);

/** Public, deterministic fixture payloads only; never receives user secrets. */
$action = $argv[1] ?? '';
$workDirectory = null;
try {
    $password = null;
    if ($action === 'rar' || $action === 'rar5') {
        $filename = $action === 'rar' ? 'test_read_format_rar_subblock.rar.uu' : 'test_read_format_rar5_stored.rar.uu';
        $encoded = file_get_contents(__DIR__ . '/fixtures/' . $filename);
        if (!is_string($encoded) || preg_match('/\Abegin [0-7]+ [^\r\n]+\r?\n(.*)\r?\nend\r?\n?\z/sD', $encoded, $match) !== 1) {
            throw new RuntimeException('The RAR test fixture is not valid uuencoded data.');
        }
        $archive = convert_uudecode($match[1] . "\n");
        if (!is_string($archive)) { throw new RuntimeException('The RAR test fixture could not be decoded.'); }
        if ($action === 'rar') {
            $files = ['test.txt' => "test text document\r\n"];
        } else {
            $files = ['helloworld.txt' => "hello libarchive test suite!\n"];
        }
    } elseif ($action === '7z' || $action === '7z-encrypted') {
        $binary = null;
        foreach (['/usr/bin/7zz', '/usr/local/bin/7zz', '/usr/bin/7z', '/usr/local/bin/7z'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) { $binary = $candidate; break; }
        }
        if ($binary === null) { throw new RuntimeException('7-Zip is required to create the test fixture.'); }
        $workDirectory = sys_get_temp_dir() . '/archive-tools-format-fixture-' . bin2hex(random_bytes(8));
        if (!mkdir($workDirectory, 0700) || !mkdir($workDirectory . '/nested', 0700)) {
            throw new RuntimeException('The test fixture directory could not be created.');
        }
        $files = ['one.txt' => "one\n", 'nested/два.txt' => "два\n"];
        foreach ($files as $name => $contents) { file_put_contents($workDirectory . '/' . $name, $contents); }
        $command = [$binary, 'a', '-t7z', '-bd', '-bso0', '-bse2'];
        if ($action === '7z-encrypted') {
            $password = 'format-test';
            // This constant is deliberately public fixture data, not a stored secret.
            array_push($command, '-p' . $password, '-mhe=on');
        }
        array_push($command, '--', $workDirectory . '/fixture.7z', 'one.txt', 'nested/два.txt');
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workDirectory, ['LC_ALL' => 'C.UTF-8', 'LANG' => 'C.UTF-8']);
        if (!is_resource($process)) { throw new RuntimeException('The fixture archiver could not be started.'); }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException('Creating the 7-Zip test fixture failed.'); }
        $archive = file_get_contents($workDirectory . '/fixture.7z');
        if (!is_string($archive)) { throw new RuntimeException('The 7-Zip fixture could not be read.'); }
    } else {
        throw new InvalidArgumentException('Unknown archive fixture action.');
    }
    $result = ['base64' => base64_encode($archive), 'files' => array_map(base64_encode(...), $files)];
    if ($password !== null) { $result['password'] = $password; }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
} finally {
    if ($workDirectory !== null && is_dir($workDirectory)) {
        foreach (['one.txt', 'nested/два.txt', 'fixture.7z'] as $filename) {
            if (is_file($workDirectory . '/' . $filename)) { unlink($workDirectory . '/' . $filename); }
        }
        if (is_dir($workDirectory . '/nested')) { rmdir($workDirectory . '/nested'); }
        rmdir($workDirectory);
    }
}
