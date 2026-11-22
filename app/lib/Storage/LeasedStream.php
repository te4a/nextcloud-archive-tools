<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Storage;

use Closure;
use RuntimeException;

/** Own the stream and release its source/connection locks even on close failure. */
final class LeasedStream {
    public mixed $context;
    private const SCHEME = 'archivetools-leased';
    private static array $pending = [];
    private mixed $source;
    private Closure $onRead;
    private ?Closure $onClose = null;
    private string $prefix;
    private ?int $remaining;

    /** @param resource $source @return resource */
    public static function wrap(mixed $source, Closure $onRead, Closure $onClose, string $prefix = '', ?int $length = null): mixed {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)
            && !stream_wrapper_register(self::SCHEME, self::class)) {
            throw new RuntimeException('The archive read stream could not be registered.');
        }
        $token = bin2hex(random_bytes(24));
        self::$pending[$token] = [$source, $onRead, $onClose, $prefix, $length];
        try {
            $stream = fopen(self::SCHEME . '://' . $token, 'rb');
            if ($stream === false) {
                throw new RuntimeException('The archive read stream could not be opened.');
            }
            return $stream;
        } finally {
            unset(self::$pending[$token]);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
        $token = substr($path, strlen(self::SCHEME) + 3);
        if ($mode !== 'rb' || !isset(self::$pending[$token])) {
            return false;
        }
        [$this->source, $this->onRead, $this->onClose, $this->prefix, $this->remaining] = self::$pending[$token];
        unset(self::$pending[$token]);
        return true;
    }

    public function stream_read(int $count): string|false {
        $count = $this->remaining === null ? $count : min($count, $this->remaining);
        if ($count === 0) {
            return '';
        }
        if ($this->prefix !== '') {
            $data = substr($this->prefix, 0, $count);
            $this->prefix = substr($this->prefix, strlen($data));
        } else {
            $data = fread($this->source, $count);
        }
        if (is_string($data) && $data !== '') {
            if ($this->remaining !== null) {
                $this->remaining -= strlen($data);
            }
            ($this->onRead)();
        }
        return $data;
    }

    public function stream_eof(): bool { return $this->remaining === 0 || ($this->prefix === '' && feof($this->source)); }
    public function stream_tell(): int|false { return ftell($this->source); }
    public function stream_stat(): array|false { return fstat($this->source); }
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool {
        return $this->remaining === null && $this->prefix === '' && fseek($this->source, $offset, $whence) === 0;
    }
    public function stream_set_option(int $option, int $argument1, ?int $argument2): bool { return false; }

    public function stream_close(): void {
        $close = $this->onClose;
        $this->onClose = null;
        try {
            if (is_resource($this->source)) {
                fclose($this->source);
            }
        } finally {
            if ($close !== null) {
                $close();
            }
        }
    }
}
