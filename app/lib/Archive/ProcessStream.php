<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

/** @internal Bounded argv-only process; passwords use stdin, never arguments. */
final class ProcessStream {
    public mixed $context;
    private const SCHEME = 'archivetoolsprocess';
    private static array $pending = [];
    private mixed $process = null;
    private array $pipes = [];
    private ?int $exitCode = null;
    private int $received = 0;
    private string $errors = '';
    private string $outputPrefix = '';
    private bool $passwordProvided = false;
    private bool $done = false;
    private float $deadline;
    private mixed $hash = null;
    private ?IsolatedArchiveInput $input = null;
    private ?OperationBudget $budget = null;

    public static function available(): bool {
        foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $function) {
            if (!function_exists($function)) { return false; }
        }
        return is_file('/usr/bin/prlimit') && is_executable('/usr/bin/prlimit');
    }

    /** @return resource */
    public static function open(array $arguments, #[\SensitiveParameter] ?string $password, int $limit, ?int $expectedSize = null, ?int $crc = null, ?IsolatedArchiveInput $input = null, ?OperationBudget $budget = null) {
        if (!self::available() || $limit < 0 || ($password !== null && preg_match('/[\r\n\x00]/', $password))) {
            throw new \RuntimeException('The archive process cannot be started.');
        }
        if (!in_array(self::SCHEME, stream_get_wrappers(), true) && !stream_wrapper_register(self::SCHEME, self::class)) {
            throw new \RuntimeException('The archive process cannot be started.');
        }
        $token = bin2hex(random_bytes(16));
        self::$pending[$token] = [$arguments, $password, $limit, $expectedSize, $crc, $input, $budget];
        try {
            $stream = fopen(self::SCHEME . '://' . $token, 'rb');
            if ($stream === false) { throw new \RuntimeException('The archive process cannot be started.'); }
            return $stream;
        } catch (\Throwable $error) {
            $input?->close();
            throw $error;
        } finally { unset(self::$pending[$token]); }
    }

    /** @return string Safe metadata only; process stderr is never exposed. */
    public static function capture(array $arguments, #[\SensitiveParameter] ?string $password, int $limit = 16777216, ?IsolatedArchiveInput $input = null, ?OperationBudget $budget = null): string {
        $stream = self::open($arguments, $password, $limit, input: $input, budget: $budget);
        try {
            $result = stream_get_contents($stream);
            if ($result === false) { throw new \RuntimeException('The archive process could not be read.'); }
            return $result;
        } finally { fclose($stream); }
    }

    private int $limit;
    private ?int $expectedSize;
    private ?int $expectedCrc;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
        $token = substr($path, strlen(self::SCHEME) + 3);
        if (!isset(self::$pending[$token])) { return false; }
        [$arguments, $password, $this->limit, $this->expectedSize, $this->expectedCrc, $this->input, $this->budget] = self::$pending[$token];
        $seconds = min(300.0, $this->budget?->remainingSeconds() ?? 300.0);
        $cpu = max(1, (int)ceil($seconds));
        $this->passwordProvided = $password !== null && $password !== '';
        // Resource limits belong only to this child. prlimit execs the decoder,
        // keeping its PID and stdin; the PHP worker and host stay unchanged.
        $arguments = ['/usr/bin/prlimit', '--as=536870912:536870912', '--cpu=' . $cpu . ':' . $cpu, '--', ...$arguments];
        $this->process = @proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, null, ['LC_ALL' => 'C.UTF-8', 'LANG' => 'C.UTF-8'], ['bypass_shell' => true, 'suppress_errors' => true]);
        if (!is_resource($this->process)) { throw new \RuntimeException('The archive process cannot be started.'); }
        $this->deadline = microtime(true) + $seconds;
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
        if ($password !== null && $password !== '') {
            $input = $password . "\n";
            if (strlen($input) > 4096 || @fwrite($this->pipes[0], $input) !== strlen($input)) {
                $this->stream_close();
                throw new \RuntimeException('The archive password could not be provided.');
            }
        }
        fclose($this->pipes[0]);
        unset($this->pipes[0]);
        if ($this->expectedCrc !== null) { $this->hash = hash_init('crc32b'); }
        return true;
    }

    private function status(): array {
        $status = proc_get_status($this->process);
        if (!$status['running'] && $status['exitcode'] >= 0) { $this->exitCode = $status['exitcode']; }
        return $status;
    }

    private function drainErrors(): void {
        // Read at most one byte beyond the budget, never an unbounded burst.
        $chunk = stream_get_contents($this->pipes[2], 32769 - strlen($this->errors));
        if ($chunk !== false && strlen($chunk) > 0) {
            if (strlen($this->errors) + strlen($chunk) > 32768) { throw new \RuntimeException('The archive process exceeded its diagnostic limit.'); }
            $this->errors .= $chunk;
        }
    }

    private function rawRead(int $count): string {
        while (true) {
            $this->budget?->check();
            if (microtime(true) > $this->deadline) { throw new \RuntimeException('The archive operation exceeded its time limit.'); }
            $this->drainErrors();
            $bytes = fread($this->pipes[1], $count);
            if ($bytes === false) { throw new \RuntimeException('The archive process could not be read.'); }
            if ($bytes !== '') {
                $this->budget?->check();
                if (strlen($this->outputPrefix) < 128) { $this->outputPrefix .= substr($bytes, 0, 128 - strlen($this->outputPrefix)); }
                return $bytes;
            }
            $status = $this->status();
            if (!$status['running'] && feof($this->pipes[1])) {
                $this->drainErrors();
                if ($this->exitCode !== 0) {
                    if (preg_match('/not enough memory|cannot allocate|can.t allocate required memory/i', $this->errors)) {
                        throw new \RuntimeException('The archive requires more memory than the configured limit allows.');
                    }
                    // With closed stdin, 7-Zip reports only "Break signaled"
                    // on stderr; the password request itself is on stdout.
                    if (preg_match('/wrong password|encrypted archive|password (?:is incorrect|required)|enter password/i', $this->errors)
                        || preg_match('/\A\r?\nEnter password(?: \(will not be echoed\))?:\r?\n/', $this->outputPrefix)) {
                        throw new ArchivePasswordRequiredException($this->passwordProvided);
                    }
                    throw new \RuntimeException('The archive is damaged or uses an unsupported compression method.');
                }
                return '';
            }
            $read = array_values(array_filter([$this->pipes[1], $this->pipes[2]], static fn ($pipe) => !feof($pipe)));
            if ($read !== []) {
                $write = $except = null;
                $wait = max(0.0, min(1.0, $this->deadline - microtime(true), $this->budget?->remainingSeconds() ?? 1.0));
                @stream_select($read, $write, $except, (int)$wait, (int)(($wait - (int)$wait) * 1000000));
            } else { usleep(10000); }
        }
    }

    public function stream_read(int $count): string {
        if ($this->done) { return ''; }
        try {
            $data = $this->rawRead(min($count, 65536));
            $size = strlen($data);
            if ($size > $this->limit - $this->received || ($this->expectedSize !== null && $size > $this->expectedSize - $this->received)) {
                throw new \RuntimeException('The archive member exceeds its declared size or the configured limit.');
            }
            $this->received += $size;
            if ($this->hash !== null) { hash_update($this->hash, $data); }
            if ($data === '' || ($this->expectedSize !== null && $this->received === $this->expectedSize)) {
                if ($data !== '' && $this->rawRead(1) !== '') { throw new \RuntimeException('The archive member exceeds its declared size or the configured limit.'); }
                if ($this->expectedSize !== null && $this->received !== $this->expectedSize) { throw new \RuntimeException('The archive member ended before its declared size.'); }
                if ($this->hash !== null && hexdec(hash_final($this->hash)) !== $this->expectedCrc) { throw new \RuntimeException('The archive member failed its integrity check.'); }
                $this->hash = null;
                $this->done = true;
            }
            return $data;
        } catch (\Throwable $error) { $this->stream_close(); throw $error; }
    }

    public function stream_eof(): bool { return $this->done; }
    public function stream_tell(): int { return $this->received; }
    public function stream_stat(): array { return ['size' => $this->expectedSize ?? 0, 'mode' => 0100444]; }
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool { return false; }

    public function stream_close(): void {
        try {
            if (!is_resource($this->process)) { return; }
            $status = $this->status();
            if ($status['running']) { @proc_terminate($this->process, 9); }
            foreach ($this->pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            $this->pipes = [];
            @proc_close($this->process);
        } finally {
            $this->process = null;
            $this->input?->close();
            $this->input = null;
        }
    }
}
