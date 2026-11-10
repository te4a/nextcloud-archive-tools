<?php
declare(strict_types=1);

$app = getenv('ARCHIVE_TOOLS_APP_PATH') ?: (is_file('/var/www/html/custom_apps/archive_tools/lib/Archive/ArchiveReader.php')
    ? '/var/www/html/custom_apps/archive_tools' : dirname(__DIR__) . '/app');
spl_autoload_register(static function (string $class) use ($app): void {
    $prefix = 'OCA\\ArchiveTools\\Archive\\';
    if (str_starts_with($class, $prefix)) { require_once $app . '/lib/Archive/' . substr($class, strlen($prefix)) . '.php'; }
});

use OCA\ArchiveTools\Archive\ArchiveReader;
use OCA\ArchiveTools\Archive\BudgetStream;
use OCA\ArchiveTools\Archive\OperationBudget;
use OCA\ArchiveTools\Archive\OperationBudgetExceededException;
use OCA\ArchiveTools\Archive\ProcessStream;
use OCA\ArchiveTools\Archive\SevenZipReader;

$checks = 0;
$paths = [];
function budgetCheck(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function expired(callable $operation): void {
    try { $operation(); } catch (OperationBudgetExceededException $error) {
        budgetCheck($error->getMessage() === 'The archive operation exceeded its time limit.', 'Safe timeout message');
        return;
    }
    throw new RuntimeException('The operation ignored its shared deadline.');
}
function budgetTest(string $name, callable $operation): void {
    global $checks;
    $operation(); ++$checks; echo 'PASS ' . $name . PHP_EOL;
}
function fixture(string $bytes): string {
    global $paths;
    $path = tempnam(sys_get_temp_dir(), 'archive-tools-budget-');
    if ($path === false || file_put_contents($path, $bytes) !== strlen($bytes)) { throw new RuntimeException('Cannot create budget fixture.'); }
    $paths[] = $path;
    return $path;
}
function tarRecord(string $name, string $data): string {
    $head = str_pad($name, 100, "\0") . "0000644\0" . "0000000\0" . "0000000\0"
        . sprintf('%011o', strlen($data)) . "\0" . "00000000000\0" . str_repeat(' ', 8) . '0';
    $head = str_pad($head, 512, "\0");
    $sum = array_sum(unpack('C*', $head));
    $head = substr_replace($head, sprintf('%06o', $sum) . "\0 ", 148, 8);
    return $head . $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
}

try {
    budgetTest('monotonic deadline is shared and never renewed', static function (): void {
        $time = 10.0; $budget = new OperationBudget(2, static function () use (&$time): float { return $time; });
        budgetCheck($budget->remainingSeconds() === 2.0, 'Initial duration');
        $time += 1.25; budgetCheck($budget->remainingSeconds() === 0.75, 'Remaining duration');
        $time += 0.75; expired(static fn () => $budget->check());
    });
    budgetTest('invalid durations cannot bypass the bounded job policy', static function (): void {
        foreach ([-1, 301, INF, NAN] as $seconds) {
            try { new OperationBudget($seconds); } catch (InvalidArgumentException) { continue; }
            throw new RuntimeException('Invalid operation duration accepted.');
        }
        expired(static fn () => (new OperationBudget(0))->check());
    });
    budgetTest('native stream expires between chunks and closes its source', static function (): void {
        $time = 0.0; $budget = new OperationBudget(1, static function () use (&$time): float { return $time; });
        $source = fopen('php://temp', 'w+b'); fwrite($source, str_repeat('x', 131072)); rewind($source);
        $stream = BudgetStream::wrap($source, $budget);
        try {
            budgetCheck(strlen(fread($stream, 8192)) === 8192, 'First native chunk');
            $time = 2; expired(static fn () => fread($stream, 65536));
            budgetCheck(!is_resource($source), 'Expired wrapper closes source');
        } finally { fclose($stream); }
    });
    budgetTest('EOF probes enforce the budget and release the native source', static function (): void {
        $time = 0.0; $budget = new OperationBudget(1, static function () use (&$time): float { return $time; });
        $source = fopen('php://temp', 'w+b'); $stream = BudgetStream::wrap($source, $budget); $time = 2;
        try { expired(static fn () => feof($stream)); budgetCheck(!is_resource($source), 'EOF timeout closes source'); }
        finally { fclose($stream); }
    });
    budgetTest('ZIP reader does not inherit an earlier job deadline', static function (): void {
        $path = fixture(''); $zip = new ZipArchive();
        budgetCheck($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'ZIP fixture');
        $zip->addFromString('one.txt', str_repeat('x', 65536)); $zip->close();
        $reader = new ArchiveReader();
        expired(static fn () => $reader->inspect($path, null, new OperationBudget(0)));
        $index = $reader->inspect($path, null, new OperationBudget());
        budgetCheck($index['fileCount'] === 1, 'Fresh job index succeeds');
        $time = 0.0; $budget = new OperationBudget(1, static function () use (&$time): float { return $time; });
        $stream = $reader->stream($path, 'one.txt', null, $budget);
        try { fread($stream, 8192); $time = 2; expired(static fn () => fread($stream, 65536)); }
        finally { fclose($stream); }
        $stream = $reader->stream($path, 'one.txt', null, new OperationBudget());
        try { budgetCheck(strlen(stream_get_contents($stream)) === 65536, 'Next job stream succeeds'); }
        finally { fclose($stream); }
    });
    budgetTest('TAR batch shares one deadline across members without leaking it', static function (): void {
        $path = fixture(tarRecord('one.txt', 'one') . tarRecord('two.txt', 'two') . str_repeat("\0", 1024));
        $reader = new ArchiveReader(); $index = $reader->inspect($path);
        $time = 0.0; $budget = new OperationBudget(1, static function () use (&$time): float { return $time; });
        $streams = $reader->streams($path, $index['entries'], null, $budget);
        $streams->rewind(); $stream = $streams->current();
        budgetCheck(stream_get_contents($stream) === 'one', 'First TAR member'); fclose($stream);
        $time = 2;
        try { expired(static fn () => $streams->next()); } finally { unset($streams); }
        $bytes = [];
        foreach ($reader->streams($path, $index['entries'], null, new OperationBudget()) as $name => $stream) {
            $bytes[$name] = stream_get_contents($stream); fclose($stream);
        }
        budgetCheck($bytes === ['one.txt' => 'one', 'two.txt' => 'two'], 'Fresh TAR job unaffected');
    });
    budgetTest('gzip indexing checks its deadline during inflation', static function (): void {
        $path = fixture(gzencode(tarRecord('large.txt', str_repeat('x', 2097152)) . str_repeat("\0", 1024)));
        $time = 0.0; $budget = new OperationBudget(1, static function () use (&$time): float { $time += 0.04; return $time; });
        expired(static fn () => (new ArchiveReader())->inspect($path, null, $budget));
        budgetCheck((new ArchiveReader())->inspect($path)['totalSize'] === 2097152, 'Fixture is valid');
    });
    budgetTest('a short remaining budget terminates and reaps a decoder', static function (): void {
        $budget = new OperationBudget(0.3); $start = hrtime(true);
        $stream = ProcessStream::open([PHP_BINARY, '-n', '-r', 'echo getmypid()."\n";fflush(STDOUT);sleep(20);'], null, 4096, budget: $budget);
        $pid = (int)fgets($stream);
        try { expired(static fn () => stream_get_contents($stream)); } finally { fclose($stream); }
        budgetCheck($pid > 1 && !is_dir('/proc/' . $pid), 'Decoder is reaped');
        budgetCheck((hrtime(true) - $start) / 1e9 < 3, 'Deadline does not wait for per-child300s');
    });
    budgetTest('successive decoders consume one operation budget', static function (): void {
        $budget = new OperationBudget(0.8); $start = hrtime(true);
        budgetCheck(ProcessStream::capture([PHP_BINARY, '-n', '-r', 'usleep(200000);echo "first";'], null, 4096, budget: $budget) === 'first', 'First decoder succeeds');
        expired(static fn () => ProcessStream::capture([PHP_BINARY, '-n', '-r', 'sleep(20);'], null, 4096, budget: $budget));
        budgetCheck((hrtime(true) - $start) / 1e9 < 3, 'Second decoder receives remaining time');
        budgetCheck(ProcessStream::capture([PHP_BINARY, '-n', '-r', 'echo "next-job";'], null, 4096, budget: new OperationBudget()) === 'next-job', 'Next operation has an independent budget');
    });
    budgetTest('7z members share the job deadline across separate decoders', static function (): void {
        $reader = new SevenZipReader(); budgetCheck($reader->supports('7z'), 'System 7-Zip is available');
        $binary = null;
        foreach (['/usr/local/bin/7zz', '/usr/bin/7zz', '/usr/local/bin/7z', '/usr/bin/7z'] as $candidate) {
            if (is_executable($candidate)) { $binary = $candidate; break; }
        }
        global $paths;
        $one = fixture('one'); $two = fixture('two'); $temporary = fixture(''); unlink($temporary);
        $path = $temporary . '.7z'; $paths[] = $path;
        ProcessStream::capture([$binary, 'a', '-t7z', '-ms=on', '-bd', '-bso0', '--', $path, $one, $two], null, 65536);
        $index = $reader->inspect($path); $time = 0.0;
        $budget = new OperationBudget(1, static function () use (&$time): float { return $time; });
        $streams = $reader->streams($path, $index['entries'], null, $budget); $streams->rewind();
        $stream = $streams->current(); budgetCheck(strlen(stream_get_contents($stream)) === 3, 'First solid member'); fclose($stream);
        $time = 2; try { expired(static fn () => $streams->next()); } finally { unset($streams); }
        $seen = [];
        foreach ($reader->streams($path, $index['entries'], null, new OperationBudget()) as $stream) {
            $seen[] = stream_get_contents($stream); fclose($stream);
        }
        sort($seen); budgetCheck($seen === ['one', 'two'], 'Next solid archive job succeeds');
    });
} finally { foreach ($paths as $path) { if (is_file($path)) { unlink($path); } } }
echo 'All ' . $checks . ' operation budget tests passed.' . PHP_EOL;
