<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

/** Own a native stream and enforce its job deadline between bounded reads. */
final class BudgetStream {
    public mixed $context;
    private const SCHEME = 'archivetoolsbudget';
    private static array $pending = [];
    private mixed $source = null;
    private ?OperationBudget $budget = null;

    /** @param resource $source @return resource */
    public static function wrap(mixed $source, ?OperationBudget $budget) {
        if ($budget === null) { return $source; }
        try {
            $budget->check();
            if (!in_array(self::SCHEME, stream_get_wrappers(), true) && !stream_wrapper_register(self::SCHEME, self::class)) {
                throw new \RuntimeException('The archive read stream could not be registered.');
            }
            $token = bin2hex(random_bytes(16));
            self::$pending[$token] = [$source, $budget];
            try {
                $stream = fopen(self::SCHEME . '://' . $token, 'rb');
                if ($stream === false) { throw new \RuntimeException('The archive read stream could not be opened.'); }
                return $stream;
            } finally { unset(self::$pending[$token]); }
        } catch (\Throwable $error) {
            if (is_resource($source)) { fclose($source); }
            throw $error;
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
        $token = substr($path, strlen(self::SCHEME) + 3);
        if ($mode !== 'rb' || !isset(self::$pending[$token])) { return false; }
        [$this->source, $this->budget] = self::$pending[$token];
        unset(self::$pending[$token]);
        return true;
    }

    public function stream_read(int $count): string|false {
        try {
            $this->budget->check();
            $bytes = fread($this->source, min($count, 65536));
            $this->budget->check();
            return $bytes;
        } catch (\Throwable $error) { $this->stream_close(); throw $error; }
    }
    public function stream_eof(): bool {
        try { $this->budget->check(); return feof($this->source); }
        catch (\Throwable $error) { $this->stream_close(); throw $error; }
    }
    public function stream_tell(): int|false { return ftell($this->source); }
    public function stream_stat(): array|false { return fstat($this->source); }
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool { return false; }
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool { return false; }
    public function stream_close(): void { if (is_resource($this->source)) { fclose($this->source); } $this->source = null; }
}
