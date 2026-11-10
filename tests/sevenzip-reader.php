<?php
declare(strict_types=1);

use OCA\ArchiveTools\Archive\ArchivePasswordRequiredException;
use OCA\ArchiveTools\Archive\ProcessStream;
use OCA\ArchiveTools\Archive\IsolatedArchiveInput;
use OCA\ArchiveTools\Archive\SevenZipReader;

$appPath = getenv('ARCHIVE_TOOLS_APP_PATH') ?: dirname(__DIR__) . '/app';
spl_autoload_register(static function (string $class) use ($appPath): void {
    $prefix = 'OCA\\ArchiveTools\\Archive\\';
    if (str_starts_with($class, $prefix)) {
        $file = $appPath . '/lib/Archive/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
$binary = null;
foreach (['/usr/local/bin/7zz', '/usr/bin/7zz', '/usr/bin/7z'] as $candidate) {
    if (is_executable($candidate)) { $binary = $candidate; break; }
}
if ($binary === null || !extension_loaded('intl')) {
    fwrite(STDERR, "These tests require native 7-Zip and PHP intl.\n");
    exit(1);
}
$workDirectory = sys_get_temp_dir() . '/archive-tools-sevenzip-' . bin2hex(random_bytes(8));
if (!mkdir($workDirectory, 0700)) { throw new RuntimeException('Cannot create the test workspace.'); }
$passed = 0;
$failed = 0;
$fixtureCount = 0;

function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

function test(string $name, callable $operation): void {
    global $passed, $failed;
    try {
        $operation();
        ++$passed;
        echo 'PASS ' . $name . "\n";
    } catch (Throwable $error) {
        ++$failed;
        // All test assertions and reader exceptions have fixed messages; never
        // print process argv, raw metadata, stdout or stderr on failure.
        echo 'FAIL ' . $name . ': ' . $error::class . ' (' . $error->getMessage() . ")\n";
    }
}

function rejects(callable $operation, string $message, ?string $exceptionClass = null): Throwable {
    try { $operation(); }
    catch (Throwable $error) {
        if ($exceptionClass !== null && !$error instanceof $exceptionClass) { throw new RuntimeException('Expected ' . $exceptionClass . ', got ' . $error::class . ': ' . $error->getMessage()); }
        return $error;
    }
    throw new RuntimeException($message);
}

/** Public test fixture commands only; no shell and no actual user secrets. */
function fixtureCommand(array $arguments, ?string $cwd = null): string {
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd,
        ['LC_ALL' => 'C.UTF-8', 'LANG' => 'C.UTF-8'], ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start the fixture command.'); }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || !is_string($stdout) || !is_string($stderr)) { throw new RuntimeException('Fixture creation command failed.'); }
    return $stdout;
}

/** @param array<string,string> $files */
function makeArchive(array $files, array $options = [], ?callable $prepare = null): string {
    global $binary, $workDirectory, $fixtureCount;
    $number = ++$fixtureCount;
    $directory = $workDirectory . '/input-' . $number;
    mkdir($directory, 0700);
    foreach ($files as $name => $contents) {
        $parent = dirname($directory . '/' . $name);
        if (!is_dir($parent)) { mkdir($parent, 0700, true); }
        file_put_contents($directory . '/' . $name, $contents);
    }
    if ($prepare !== null) { $prepare($directory); }
    $archive = $workDirectory . '/fixture-' . $number . '.7z';
    fixtureCommand([$binary, 'a', '-t7z', '-bd', '-bso0', '-bse2', ...$options, '--', $archive, '.'], $directory);
    return $archive;
}

/** Fault-inject a listing into the real reader/parser through a test-only executable. */
function listingReader(string $listing): SevenZipReader {
    global $workDirectory, $fixtureCount;
    $file = $workDirectory . '/listing-' . (++$fixtureCount) . '.php';
    $program = '#!' . PHP_BINARY . " -n\n<?php\n"
        . 'if (($argv[1] ?? "") === "i") { echo "   C........  7z  7z\n"; }'
        . ' else { echo base64_decode(' . var_export(base64_encode($listing), true) . '); }';
    file_put_contents($file, $program);
    chmod($file, 0700);
    $reader = new SevenZipReader();
    (new ReflectionProperty(SevenZipReader::class, 'binary'))->setValue($reader, $file);
    return $reader;
}

function readMember(SevenZipReader $reader, string $archive, string $member, ?string $password = null): string {
    $stream = $reader->stream($archive, $member, $password);
    try {
        $bytes = stream_get_contents($stream);
        if (!is_string($bytes)) { throw new RuntimeException('The member stream could not be read.'); }
        return $bytes;
    } finally { fclose($stream); }
}

/** Remove only this test's own tree; never follow symbolic links. */
function removeFixtureTree(string $directory): void {
    global $workDirectory;
    if ($directory !== $workDirectory && !str_starts_with($directory, $workDirectory . '/')) { throw new RuntimeException('Unsafe fixture cleanup target.'); }
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isLink() || !$entry->isDir()) { unlink($entry->getPathname()); }
        else { removeFixtureTree($entry->getPathname()); }
    }
    rmdir($directory);
}

try {
    $normal = makeArchive(['plain.txt' => 'alpha', 'Каталог с пробелом/данные%2F#?.txt' => "binary\0\xff", 'empty.txt' => '']);
    test('current native 7-Zip format table is recognized', static function (): void {
        $reader = new SevenZipReader();
        check($reader->available() && $reader->supports('7z'), 'Installed 7-Zip must expose its 7z decoder.');
        check($reader->supports('bzip2') && $reader->supports('xz'), 'Installed 7-Zip must expose compressed TAR decoders.');
    });

    test('7z metadata and streams preserve Unicode, punctuation, binary and empty files', static function () use ($normal): void {
        $reader = new SevenZipReader();
        $index = $reader->inspect($normal);
        check($index['format'] === '7z' && $index['fileCount'] === 3 && $index['totalSize'] === 13, 'Metadata counts and sizes must match actual entries.');
        check(readMember($reader, $normal, 'plain.txt') === 'alpha', 'Plain member content differs.');
        check(readMember($reader, $normal, 'Каталог с пробелом/данные%2F#?.txt') === "binary\0\xff", 'Unicode member content differs.');
        check(readMember($reader, $normal, 'empty.txt') === '', 'An empty member must remain readable.');
    });

    test('empty archives and empty-directory leaves pass literal-name verification', static function (): void {
        check((new SevenZipReader())->inspect(makeArchive([]))['fileCount'] === 0, 'An empty archive must remain valid.');
        $archive = makeArchive([], [], static function (string $directory): void {
            mkdir($directory . '/empty_one', 0700);
            mkdir($directory . '/Каталог', 0700);
            mkdir($directory . '/Каталог/empty_two', 0700);
        });
        $index = (new SevenZipReader())->inspect($archive);
        check($index['fileCount'] === 0 && $index['directoryCount'] === 3, 'Empty directories must not disappear during verification.');
    });

    test('wildcard-looking member names select exactly one literal file', static function (): void {
        $archive = makeArchive(['*.txt' => 'star', '?.txt' => 'question', '[x].txt' => 'brackets', 'other.txt' => 'other']);
        $reader = new SevenZipReader();
        foreach (['*.txt' => 'star', '?.txt' => 'question', '[x].txt' => 'brackets'] as $name => $bytes) {
            check(readMember($reader, $archive, $name) === $bytes, 'Wildcard syntax must not expand member selection.');
        }
    });

    test('leading at-sign and dash members never become list files or command options', static function (): void {
        $archive = makeArchive(['@not-a-list-file.txt' => 'at sign', '-unexpected-switch.txt' => 'dash']);
        $reader = new SevenZipReader();
        check(readMember($reader, $archive, '@not-a-list-file.txt') === 'at sign', 'A leading at-sign must be a literal member name.');
        check(readMember($reader, $archive, '-unexpected-switch.txt') === 'dash', 'A leading dash must be a literal member name.');
    });

    test('a member selector does not include same-basename files from nested folders', static function (): void {
        $archive = makeArchive(['same.txt' => 'root', 'nested/same.txt' => 'nested']);
        $reader = new SevenZipReader();
        check(readMember($reader, $archive, 'same.txt') === 'root', 'Selection must not concatenate nested matches.');
        check(readMember($reader, $archive, 'nested/same.txt') === 'nested', 'Nested member selection differs.');
    });

    test('encrypted content requires a password but permits metadata inspection', static function (): void {
        $password = 'archive-tools-public-fixture';
        $archive = makeArchive(['secret.txt' => 'protected'], ['-p' . $password]);
        $reader = new SevenZipReader();
        check($reader->inspect($archive)['encrypted'] === true, 'Protected members must be reported as encrypted.');
        rejects(static fn () => readMember($reader, $archive, 'secret.txt'), 'Missing password was accepted.', ArchivePasswordRequiredException::class);
        rejects(static fn () => readMember($reader, $archive, 'secret.txt', 'wrong-public-password'), 'Wrong password was accepted.', ArchivePasswordRequiredException::class);
        check(readMember($reader, $archive, 'secret.txt', $password) === 'protected', 'Correct password must decrypt the member.');
    });

    test('encrypted headers use a structured password-required exception', static function (): void {
        $password = 'archive-tools-public-fixture';
        $archive = makeArchive(['hidden/file.txt' => 'hidden'], ['-p' . $password, '-mhe=on']);
        $reader = new SevenZipReader();
        $missing = rejects(static fn () => $reader->inspect($archive), 'Encrypted headers were listed without a password.', ArchivePasswordRequiredException::class);
        $wrong = rejects(static fn () => $reader->inspect($archive, 'wrong-public-password'), 'Encrypted headers accepted a wrong password.', ArchivePasswordRequiredException::class);
        check($missing->getMessage() !== $wrong->getMessage(), 'An incorrect supplied password must have a useful distinct message.');
        check($reader->inspect($archive, $password)['fileCount'] === 1, 'Correct password must list encrypted headers.');
        check(readMember($reader, $archive, 'hidden/file.txt', $password) === 'hidden', 'Encrypted-header member content differs.');
    });

    test('symbolic links are rejected before publishing metadata', static function (): void {
        $archive = makeArchive(['regular.txt' => 'regular'], ['-snl'], static function (string $directory): void {
            check(symlink('regular.txt', $directory . '/link.txt'), 'Cannot create the symlink fixture.');
        });
        rejects(static fn () => (new SevenZipReader())->inspect($archive), 'A symbolic link was accepted.');
    });

    test('Unicode normalization collisions are rejected', static function (): void {
        $archive = makeArchive(["e\u{0301}.txt" => 'one', "\u{00e9}.txt" => 'two']);
        rejects(static fn () => (new SevenZipReader())->inspect($archive), 'NFC-colliding names were accepted.');
    });

    test('control-character filenames cannot silently alias technical-listing names', static function (): void {
        $archive = makeArchive(["safe\nSize = 0\n\nPath = forged.txt" => 'data']);
        rejects(static fn () => (new SevenZipReader())->inspect($archive), 'A newline filename injected technical-listing entries.');
    });

    test('sanitized control names in empty directories are rejected', static function (): void {
        $archive = makeArchive([], [], static function (string $directory): void { mkdir($directory . "/bad\nname", 0700); });
        rejects(static fn () => (new SevenZipReader())->inspect($archive), 'A renamed empty-directory alias was accepted.');
    });

    test('entry count and expanded-byte budgets apply to 7z listings', static function (): void {
        $archive = makeArchive(['a.txt' => '1234', 'b.txt' => '5678']);
        rejects(static fn () => (new SevenZipReader(maxEntries: 1))->inspect($archive), 'Entry-count budget was bypassed.');
        rejects(static fn () => (new SevenZipReader(maxExpandedBytes: 7))->inspect($archive), 'Expanded-byte budget was bypassed.');
    });

    test('batch streams preserve selected keys and reject tampered plans before yielding bytes', static function (): void {
        $archive = makeArchive(['first.txt' => 'first', 'nested/last.txt' => 'last']);
        $reader = new SevenZipReader();
        $index = $reader->inspect($archive);
        $bytes = [];
        foreach ($reader->streams($archive, $index['entries']) as $member => $stream) { $bytes[$member] = stream_get_contents($stream); }
        check($bytes === ['first.txt' => 'first', 'nested/last.txt' => 'last'], 'Batch streams must preserve validated member keys and bytes.');
        foreach (['originalName' => 'first.txt', 'size' => 999, 'crc' => 0, 'type' => 'dir', 'encrypted' => true] as $field => $badValue) {
            $plan = $index['entries'];
            $plan['nested/last.txt'][$field] = $badValue;
            $yielded = false;
            rejects(static function () use ($reader, $archive, $plan, &$yielded): void {
                foreach ($reader->streams($archive, $plan) as $stream) { $yielded = true; }
            }, 'A tampered extraction plan was accepted.');
            check(!$yielded, 'The complete plan must be checked before the first file is yielded.');
        }
    });

    foreach (['rar', 'rar5'] as $rarFormat) {
        test($rarFormat . ' fixtures normalize format IDs and yield complete binary members', static function () use ($rarFormat): void {
            global $workDirectory;
            $fixture = json_decode(fixtureCommand([PHP_BINARY, '-n', __DIR__ . '/format-fixtures.php', $rarFormat]), true, 512, JSON_THROW_ON_ERROR);
            $archive = $workDirectory . '/' . $rarFormat . '.rar';
            file_put_contents($archive, base64_decode($fixture['base64'], true));
            $reader = new SevenZipReader();
            $index = $reader->inspect($archive);
            check($index['format'] === 'rar' && $index['fileCount'] === count($fixture['files']), 'RAR revisions must share one public format ID.');
            foreach ($reader->streams($archive, $index['entries']) as $member => $stream) {
                check(base64_encode(stream_get_contents($stream)) === $fixture['files'][$member], 'RAR fixture bytes differ from the independent manifest.');
            }
        });
    }

    $listing = fixtureCommand([$binary, 'l', '-slt', '-ba', '-sccUTF-8', '--', $normal]);
    test('listing parser rejects duplicate properties and invalid UTF-8 paths', static function () use ($normal, $listing): void {
        $duplicate = preg_replace('/^Path = plain\.txt$/m', "Path = plain.txt\nPath = duplicate.txt", $listing);
        rejects(static fn () => listingReader($duplicate)->inspect($normal), 'Duplicate listing properties were accepted.');
        $invalid = preg_replace('/^Path = plain\.txt$/m', "Path = invalid\xff.txt", $listing);
        rejects(static fn () => listingReader($invalid)->inspect($normal), 'Invalid UTF-8 metadata was accepted.');
    });

    test('listing parser rejects absolute and parent-directory paths', static function () use ($normal, $listing): void {
        foreach (['/absolute.txt', '../escape.txt', 'C:\\escape.txt'] as $name) {
            $modified = preg_replace_callback('/^Path = plain\.txt$/m', static fn (): string => 'Path = ' . $name, $listing);
            rejects(static fn () => listingReader($modified)->inspect($normal), 'An unsafe listing path was accepted.');
        }
    });

    test('metadata parsing bounds tiny-record floods before materializing an index', static function (): void {
        $reader = new SevenZipReader(maxEntries: 10);
        $parser = new ReflectionMethod(SevenZipReader::class, 'metadata');
        $yielded = 0;
        rejects(static function () use ($reader, $parser, &$yielded): void {
            foreach ($parser->invoke($reader, str_repeat("Path = tiny\nSize = 0\n\n", 100000)) as $_record) { ++$yielded; }
        }, 'A hostile flood of tiny metadata records was accepted.');
        check($yielded === 10, 'Parsing must stop at the configured entry budget.');
    });

    test('metadata records have bounded property counts and individual line lengths', static function (): void {
        $reader = new SevenZipReader();
        $parser = new ReflectionMethod(SevenZipReader::class, 'metadata');
        $base = "Path = valid.txt\nSize = 0\n";
        for ($index = 0; $index < 62; ++$index) { $base .= 'Property ' . $index . " = value\n"; }
        check(count(iterator_to_array($parser->invoke($reader, $base . "\n"))) === 1, 'Exactly 64 properties must remain valid.');
        rejects(static fn () => iterator_to_array($parser->invoke($reader, $base . "Extra = value\n\n")), 'An excessive property count was accepted.');
        rejects(static fn () => iterator_to_array($parser->invoke($reader, "Path = valid.txt\nSize = 0\nComment = " . str_repeat('x', 8192) . "\n\n")), 'An excessive metadata line was accepted.');
    });

    test('process passwords travel only through stdin, never argv', static function (): void {
        $password = 'public-process-password-fixture';
        $output = ProcessStream::capture([PHP_BINARY, '-n', '-r', 'echo json_encode(["argv"=>$argv,"input"=>stream_get_contents(STDIN)]);'], $password, 65536);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        check($result['input'] === $password . "\n", 'The password must arrive through stdin.');
        check(!str_contains(json_encode($result['argv'], JSON_THROW_ON_ERROR), $password), 'A password appeared in process argv.');
        foreach (["line\nbreak", "line\rbreak", "nul\0break", str_repeat('x', 4096)] as $invalid) {
            rejects(static fn () => ProcessStream::capture([PHP_BINARY, '-n', '-r', 'stream_get_contents(STDIN);'], $invalid), 'An unsupported password was accepted.');
        }
    });

    test('stdout and stderr are bounded and private diagnostic text is not exposed', static function (): void {
        rejects(static fn () => ProcessStream::capture([PHP_BINARY, '-n', '-r', 'echo str_repeat("x", 1048576);'], null, 1024), 'The stdout limit was bypassed.');
        $error = rejects(static fn () => ProcessStream::capture([PHP_BINARY, '-n', '-r', 'fwrite(STDERR, str_repeat("private-diagnostic-marker",10000)); exit(2);'], null), 'The stderr limit was bypassed.');
        check(!str_contains($error->getMessage(), 'private-diagnostic-marker'), 'Private stderr escaped through an exception.');
        $error = rejects(static fn () => ProcessStream::capture([PHP_BINARY, '-n', '-r', 'fwrite(STDERR,"private-password-marker: wrong password"); exit(2);'], null), 'An unsuccessful command was accepted.', ArchivePasswordRequiredException::class);
        check(!str_contains($error->getMessage(), 'private-password-marker'), 'A password diagnostic escaped through an exception.');
    });

    test('decoder children have hard memory and CPU limits without changing the parent', static function (): void {
        $before = file_get_contents('/proc/self/limits');
        $limits = ProcessStream::capture([PHP_BINARY, '-n', '-r', 'echo file_get_contents("/proc/self/limits");'], null, 65536);
        check(preg_match('/^Max address space\s+536870912\s+536870912\s+bytes\s*$/m', $limits) === 1, 'The decoder needs a 512 MiB address-space hard limit.');
        check(preg_match('/^Max cpu time\s+300\s+300\s+seconds\s*$/m', $limits) === 1, 'The decoder needs a 300-second CPU hard limit.');
        rejects(static fn () => ProcessStream::capture([PHP_BINARY, '-n', '-r', 'ini_set("memory_limit", "-1"); $large = str_repeat("x", 1073741824); echo "unexpected-allocation-success";'], null, 65536), 'A 1 GiB allocation bypassed the decoder memory limit.');
        check(file_get_contents('/proc/self/limits') === $before, 'Child limits must not change the parent limits.');
    });

    test('decoder allocation failures have a useful fixed message without exposing stderr', static function (): void {
        $error = rejects(static fn () => ProcessStream::capture([PHP_BINARY, '-n', '-r', 'fwrite(STDERR, "ERROR: Cannot allocate required memory: private-memory-marker"); exit(8);'], null), 'A decoder memory failure was accepted.');
        check($error->getMessage() === 'The archive requires more memory than the configured limit allows.', 'A decoder memory failure needs the fixed memory-limit message.');
    });

    test('isolated input has a private opaque alias and stream lifetime never deletes its source', static function () use ($normal): void {
        $input = new IsolatedArchiveInput($normal);
        $alias = $input->path();
        $directory = dirname($alias);
        check(basename($alias) === 'archive.bin' && (fileperms($directory) & 0777) === 0700, 'The alias must have an opaque name in a private directory.');
        check(hash_file('sha256', $alias) === hash_file('sha256', $normal), 'The alias must reference the authorized input unchanged.');
        $stream = ProcessStream::open([PHP_BINARY, '-n', '-r', 'echo "ready\n"; fflush(STDOUT); sleep(20);'], null, 65536, input: $input);
        unset($input);
        check(fgets($stream) === "ready\n" && is_file($alias), 'The stream must retain its alias while its decoder is active.');
        fclose($stream);
        clearstatcache();
        check(!file_exists($alias) && !is_dir($directory) && is_file($normal), 'Closing a stream must remove only its alias and directory.');
    });

    test('isolated inputs are removed on decoder failure while the source survives', static function () use ($normal): void {
        $input = new IsolatedArchiveInput($normal);
        $alias = $input->path();
        $stream = ProcessStream::open([PHP_BINARY, '-n', '-r', 'exit(2);'], null, 65536, input: $input);
        unset($input);
        try { rejects(static fn () => stream_get_contents($stream), 'The failing decoder was accepted.'); }
        finally { fclose($stream); }
        clearstatcache();
        check(!is_dir(dirname($alias)) && is_file($normal), 'Decoder failure leaked an isolated input or removed its source.');
    });

    test('orphan cleanup removes only expired private aliases and never follows foreign directories', static function () use ($normal): void {
        global $workDirectory;
        $stale = new IsolatedArchiveInput($normal);
        $staleSymlink = new IsolatedArchiveInput($normal);
        $active = new IsolatedArchiveInput($normal);
        $foreign = new IsolatedArchiveInput($normal);
        $permissive = new IsolatedArchiveInput($normal);
        $staleAlias = $stale->path();
        $staleSymlinkAlias = $staleSymlink->path();
        $activeAlias = $active->path();
        $foreignAlias = $foreign->path();
        $permissiveAlias = $permissive->path();
        $sourceHash = hash_file('sha256', $normal);
        $directoryLink = sys_get_temp_dir() . '/archive-tools-input-' . bin2hex(random_bytes(16));
        $foreignTarget = $workDirectory . '/foreign-cleanup-target';
        mkdir($foreignTarget, 0700);
        file_put_contents($foreignTarget . '/archive.bin', 'unrelated content');
        symlink($foreignTarget, $directoryLink);
        try {
            unlink($staleSymlinkAlias);
            symlink($normal, $staleSymlinkAlias);
            file_put_contents(dirname($foreignAlias) . '/foreign.txt', 'keep this file');
            chmod(dirname($permissiveAlias), 0755);
            foreach ([$staleAlias, $staleSymlinkAlias, $foreignAlias, $permissiveAlias] as $alias) { touch(dirname($alias), time() - 7200); }
            touch($foreignTarget, time() - 7200);
            clearstatcache();
            check(IsolatedArchiveInput::cleanupExpired() >= 2, 'Expired private inputs were not cleaned.');
            clearstatcache();
            check(!is_dir(dirname($staleAlias)) && !is_dir(dirname($staleSymlinkAlias)), 'A stale regular or symlink alias survived cleanup.');
            check(is_file($activeAlias), 'An active input was removed.');
            check(is_file($foreignAlias) && is_file(dirname($foreignAlias) . '/foreign.txt'), 'A directory with foreign content was touched.');
            check(is_file($permissiveAlias), 'A directory without exact private permissions was touched.');
            check(is_link($directoryLink) && file_get_contents($foreignTarget . '/archive.bin') === 'unrelated content', 'A foreign directory symlink was followed.');
            check(hash_file('sha256', $normal) === $sourceHash, 'Orphan cleanup changed the authorized source.');
        } finally {
            @unlink($directoryLink);
            @unlink(dirname($foreignAlias) . '/foreign.txt');
            chmod(dirname($permissiveAlias), 0700);
            foreach ([$stale, $staleSymlink, $active, $foreign, $permissive] as $input) { $input->close(); }
        }
    });

    test('RAR4 and RAR5 multipart flags reject inputs before any decoder starts', static function (): void {
        global $workDirectory;
        foreach (['rar4' => ['test_rar_multivolume_single_file.part1.rar', 'test_rar_multivolume_single_file.part2.rar', 'test_rar_multivolume_single_file.part3.rar'],
                  'rar5' => ['test_read_format_rar5_multiarchive.part01.rar', 'test_read_format_rar5_multiarchive.part02.rar']] as $version => $names) {
            $directory = $workDirectory . '/' . $version . '-multipart';
            mkdir($directory, 0700);
            foreach ($names as $name) {
                $encoded = file_get_contents(__DIR__ . '/fixtures/' . $name . '.uu');
                check(preg_match('/\Abegin [0-7]+ [^\r\n]+\r?\n(.*)\r?\nend\r?\n?\z/sD', $encoded, $match) === 1, 'The multipart fixture is malformed.');
                file_put_contents($directory . '/' . $name, convert_uudecode($match[1] . "\n"));
            }
            $reader = new SevenZipReader();
            (new ReflectionProperty(SevenZipReader::class, 'binary'))->setValue($reader, '/no-decoder-may-be-started');
            $error = rejects(static fn () => $reader->inspect($directory . '/' . $names[0]), 'A multipart archive was accepted.');
            check($error->getMessage() === 'Multipart archives are not supported.', 'Multipart flags must be rejected before decoder discovery or launch.');
        }
    });

    test('decoder aliases cannot follow genuine neighboring RAR volumes', static function (): void {
        global $workDirectory, $binary;
        $source = $workDirectory . '/rar4-multipart/test_rar_multivolume_single_file.part1.rar';
        $arguments = [$binary, 'x', '-so', '-bso0', '-bd', '-bse2', '-spd', '-trar', '--', $source, 'LibarchiveAddingTest.html'];
        check(strlen(fixtureCommand($arguments)) === 20111, 'The neighboring fixture volumes must form a genuinely readable multipart set.');
        $input = new IsolatedArchiveInput($source);
        $arguments[count($arguments) - 2] = $input->path();
        rejects(static fn () => ProcessStream::capture($arguments, null, 65536, $input), 'An isolated decoder read neighboring private volumes.');
        check(is_file($source) && is_file(dirname($source) . '/test_rar_multivolume_single_file.part2.rar'), 'The neighboring source files must remain untouched.');
        // A cross-filesystem fallback may use a symlink. Prove the decoder uses
        // its literal directory, rather than resolving the source's neighbors.
        global $fixtureCount;
        $directory = $workDirectory . '/symlink-alias-' . (++$fixtureCount);
        mkdir($directory, 0700);
        symlink($source, $directory . '/archive.bin');
        $arguments[count($arguments) - 2] = $directory . '/archive.bin';
        rejects(static fn () => ProcessStream::capture($arguments, null, 65536), 'A symlink alias exposed its source directory volumes.');
    });

    test('split 7z inputs are rejected even when all neighboring pieces exist', static function (): void {
        global $workDirectory, $binary;
        $directory = $workDirectory . '/split-7z';
        mkdir($directory, 0700);
        file_put_contents($directory . '/public.bin', random_bytes(1024));
        $archive = $directory . '/split.7z';
        fixtureCommand([$binary, 'a', '-t7z', '-bd', '-bso0', '-v256b', '--', $archive, 'public.bin'], $directory);
        check(is_file($archive . '.001') && is_file($archive . '.002'), 'The split-archive fixture did not contain multiple pieces.');
        rejects(static fn () => (new SevenZipReader())->inspect($archive . '.001'), 'A split archive was combined with neighboring pieces.');
    });

    test('member streams enforce exact declared sizes and CRC integrity', static function (): void {
        foreach ([[4, null], [2, null], [3, 0]] as [$size, $crc]) {
            $stream = ProcessStream::open([PHP_BINARY, '-n', '-r', 'echo "abc";'], null, 1024, $size, $crc);
            try { rejects(static fn () => stream_get_contents($stream), 'An incorrect size or CRC was accepted.'); }
            finally { fclose($stream); }
        }
        $stream = ProcessStream::open([PHP_BINARY, '-n', '-r', 'echo "abc";'], null, 1024, 3, (int)hexdec(hash('crc32b', 'abc')));
        try { check(stream_get_contents($stream) === 'abc', 'A valid exact-size CRC stream was rejected.'); }
        finally { fclose($stream); }
    });

    test('closing a partially read stream terminates and reaps its child promptly', static function (): void {
        $stream = ProcessStream::open([PHP_BINARY, '-n', '-r', 'echo getmypid()."\n"; fflush(STDOUT); sleep(20);'], null, 65536);
        $pid = (int)fgets($stream);
        check($pid > 1 && is_dir('/proc/' . $pid), 'The child fixture did not start.');
        $started = microtime(true);
        fclose($stream);
        clearstatcache(true, '/proc/' . $pid);
        check(microtime(true) - $started < 2 && !is_dir('/proc/' . $pid), 'Early close did not promptly terminate and reap the child.');
    });

    test('an expired operation deadline terminates and reaps its child', static function (): void {
        $stream = ProcessStream::open([PHP_BINARY, '-n', '-r', 'echo getmypid()."\n"; fflush(STDOUT); sleep(20);'], null, 65536);
        $pid = (int)fgets($stream);
        $wrapper = stream_get_meta_data($stream)['wrapper_data'];
        (new ReflectionProperty(ProcessStream::class, 'deadline'))->setValue($wrapper, microtime(true) - 1);
        try { rejects(static fn () => fread($stream, 1), 'An expired deadline was ignored.'); }
        finally { fclose($stream); }
        check(!is_dir('/proc/' . $pid), 'Deadline failure did not reap the child.');
    });

    echo 'SevenZip reader tests: ' . $passed . ' passed, ' . $failed . " failed.\n";
} finally {
    removeFixtureTree($workDirectory);
}
exit($failed === 0 ? 0 : 1);
