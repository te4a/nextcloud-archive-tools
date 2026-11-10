<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

use RuntimeException;
use SensitiveParameter;

/** Read-only TAR/PAX/GNU reader. No archive member is extracted onto the host. */
final class TarReader {

    private const BLOCK = 512;
    private const MAX_METADATA_RECORD = 65536;
    private const MAX_TRAILING_PADDING = 1048576;
    private float $readDeadline = 0;

    public function __construct(
        private readonly int $maxEntries = 10000,
        private readonly int $maxExpandedBytes = 4294967296,
        private readonly int $maxDepth = 32,
        private readonly int $maxIndexNameBytes = 8388608,
        private readonly ?SevenZipReader $external = null,
    ) {
        // Validate the shared policy without opening an archive.
        $this->builder();
    }

    public function inspect(string $path, #[SensitiveParameter] ?string $password = null, ?OperationBudget $budget = null): array {
        [$source, $format] = $this->open($path, $budget);
        $index = $this->builder();
        try {
            foreach ($this->members($source) as $member) {
                $budget?->check();
                $index->add($member['name'], $member['type'], $member['size'], $member['mtime']);
            }
            $compressedSize = @filesize($path);
            if (!is_int($compressedSize) || $compressedSize < 0) {
                throw new RuntimeException('The TAR archive size could not be read.');
            }
            return $index->result($format, $compressedSize);
        } finally {
            fclose($source);
        }
    }

    /** @return resource A bounded, non-seekable stream owning the archive source. */
    public function stream(string $path, string $originalName, #[SensitiveParameter] ?string $password = null, ?OperationBudget $budget = null) {
        $policy = $this->builder();
        $policy->normalizePath($originalName);
        [$source] = $this->open($path, $budget);
        $transferred = false;
        try {
            foreach ($this->members($source) as $member) {
                $policy->add($member['name'], $member['type'], $member['size'], $member['mtime']);
                if ($member['name'] === $originalName) {
                    if ($member['type'] !== 'file') {
                        throw new RuntimeException('The requested TAR member is not a readable file.');
                    }
                    $stream = TarMemberStream::wrap($source, $member['size'], $member['mtime']);
                    if ($budget !== null) { $stream = BudgetStream::wrap($stream, $budget); }
                    $transferred = true;
                    return $stream;
                }
            }
            throw new RuntimeException('The requested file is not present in the TAR archive.');
        } finally {
            if (!$transferred && is_resource($source)) {
                fclose($source);
            }
        }
    }

    /**
     * Single-pass extraction. Consume or close each yielded stream before
     * advancing the generator. Destroying the generator closes every handle.
     * @return \Generator<string,resource>
     */
    public function streams(string $path, array $selectedEntries, #[SensitiveParameter] ?string $password = null, ?OperationBudget $budget = null): \Generator {
        $budget?->check();
        $policy = $this->builder();
        $wanted = [];
        foreach ($selectedEntries as $selectedPath => $entry) {
            $budget?->check();
            if (($entry['type'] ?? null) === 'dir') { continue; }
            $original = $entry['originalName'] ?? null;
            if (!is_string($original) || !is_int($entry['size'] ?? null) || $entry['size'] < 0 || isset($wanted[$original])) {
                throw new RuntimeException('The selected archive entries are invalid or ambiguous.');
            }
            $policy->normalizePath((string)$selectedPath);
            $wanted[$original] = ['path' => (string)$selectedPath, 'size' => $entry['size']];
        }
        if (count($wanted) > $this->maxEntries) { throw new RuntimeException('Too many archive entries are selected.'); }
        [$source] = $this->open($path, $budget);
        $stream = null;
        try {
            $members = $this->members($source);
            $members->rewind();
            while ($members->valid()) {
                $budget?->check();
                $member = $members->current();
                $policy->add($member['name'], $member['type'], $member['size'], $member['mtime']);
                $read = 0;
                if (isset($wanted[$member['name']])) {
                    $selected = $wanted[$member['name']];
                    if ($member['type'] !== 'file' || $selected['size'] !== $member['size']) {
                        throw new RuntimeException('The selected archive entry changed before it could be read.');
                    }
                    $state = new TarSliceState();
                    $stream = TarMemberStream::wrap($source, $member['size'], $member['mtime'], $state, false);
                    if ($budget !== null) { $stream = BudgetStream::wrap($stream, $budget); }
                    try { yield $selected['path'] => $stream; }
                    finally {
                        if (is_resource($stream)) { fclose($stream); }
                        $stream = null;
                    }
                    $read = $state->position;
                    unset($wanted[$member['name']]);
                }
                $members->send($read);
            }
            if ($wanted !== []) { throw new RuntimeException('A selected file is not present in the TAR archive.'); }
        } finally {
            if (is_resource($stream)) { fclose($stream); }
            fclose($source);
        }
    }

    private function builder(): EntryIndexBuilder {
        return new EntryIndexBuilder($this->maxEntries, $this->maxExpandedBytes, $this->maxDepth, $this->maxIndexNameBytes);
    }

    /** @return array{resource,string} */
    private function open(string $path, ?OperationBudget $budget = null): array {
        $budget?->check();
        $source = @fopen($path, 'rb');
        if ($source === false) {
            throw new RuntimeException('The TAR archive is not readable.');
        }
        try { $head = fread($source, 6); } finally { fclose($source); }
        if (!is_string($head)) {
            throw new RuntimeException('The TAR archive could not be read.');
        }
        $format = 'tar';
        if (str_starts_with($head, "\x1f\x8b")) {
            if (!function_exists('gzopen')) {
                throw new RuntimeException('TAR.GZ archives require the PHP zlib extension.');
            }
            $format = 'tar.gz';
            $source = GzipTarStream::wrap($path, $this->processedByteBudget(), $budget);
        } elseif (str_starts_with($head, 'BZh') || str_starts_with($head, "\xfd7zXZ\0")) {
            $format = str_starts_with($head, 'BZh') ? 'tar.bz2' : 'tar.xz';
            $external = $this->external ?? new SevenZipReader();
            $source = $external->compressedTarStream($path, $budget);
        } else {
            $source = @fopen($path, 'rb');
        }
        if (!is_resource($source)) {
            throw new RuntimeException('The TAR archive could not be opened.');
        }
        return [$budget === null ? $source : BudgetStream::wrap($source, $budget), $format];
    }

    /**
     * Yield with the source positioned at the member payload. On resumption,
     * consume that payload; callers returning a member stream retain ownership.
     * @param resource $source
     * @return \Generator<int,array{name:string,type:string,size:int,mtime:int}>
     */
    private function members(mixed $source): \Generator {
        $global = [];
        $local = [];
        $longName = null;
        $headers = 0;
        $metadataBytes = 0;
        $consumed = 0;
        $expanded = 0;
        $started = microtime(true);
        $this->readDeadline = $started + 300;
        $byteBudget = $this->processedByteBudget();
        $headerBudget = min($this->maxEntries, 1000000) * 4 + 2;
        while (true) {
            if (microtime(true) - $started > 300) {
                throw new RuntimeException('Reading the TAR archive exceeded the time limit.');
            }
            $header = $this->readExact($source, self::BLOCK, $consumed, $byteBudget);
            if ($header === str_repeat("\0", self::BLOCK)) {
                if ($longName !== null || $local !== []) {
                    throw new RuntimeException('The TAR archive ends with incomplete extended metadata.');
                }
                if ($this->readExact($source, self::BLOCK, $consumed, $byteBudget) !== str_repeat("\0", self::BLOCK)) {
                    throw new RuntimeException('The TAR archive has an invalid end marker.');
                }
                $padding = 0;
                // Read to actual EOF, including decoder CRC and subprocess exit.
                while (!feof($source)) {
                    $tail = @fread($source, 8192);
                    if ($tail === false || ($tail === '' && !feof($source))) {
                        throw new RuntimeException('Reading the TAR archive trailer failed.');
                    }
                    $padding += strlen($tail);
                    if ($padding > self::MAX_TRAILING_PADDING || strspn($tail, "\0") !== strlen($tail)) {
                        throw new RuntimeException('The TAR archive has excessive or nonzero trailing data.');
                    }
                }
                return;
            }
            if (++$headers > $headerBudget) {
                throw new RuntimeException('The TAR archive contains too many headers.');
            }
            $expected = $this->number(substr($header, 148, 8), false);
            $checked = substr_replace($header, str_repeat(' ', 8), 148, 8);
            $sum = array_sum(unpack('C*', $checked));
            if ($expected !== $sum) {
                throw new RuntimeException('The TAR archive contains an invalid header checksum.');
            }
            $name = $this->headerString(substr($header, 0, 100));
            if (substr($header, 257, 5) === 'ustar') {
                $prefix = $this->headerString(substr($header, 345, 155));
                if ($prefix !== '') { $name = $prefix . '/' . $name; }
            }
            $size = $this->number(substr($header, 124, 12));
            $mtime = $this->number(substr($header, 136, 12));
            $type = $header[156];
            if (in_array($type, ['x', 'g', 'L'], true)) {
                if ($size > self::MAX_METADATA_RECORD || $size > $this->maxIndexNameBytes - $metadataBytes) {
                    throw new RuntimeException('The TAR archive exceeds the extended metadata size limit.');
                }
                $metadataBytes += $size;
                $data = $this->readExact($source, $size, $consumed, $byteBudget);
                $this->skip($source, (self::BLOCK - $size % self::BLOCK) % self::BLOCK, $consumed, $byteBudget);
                if ($type === 'L') {
                    if ($longName !== null || $data === '' || !str_ends_with($data, "\0")) {
                        throw new RuntimeException('The TAR archive contains invalid GNU filename metadata.');
                    }
                    $longName = substr($data, 0, -1);
                } else {
                    $values = $this->pax($data);
                    if ($type === 'g') {
                        foreach ($values as $key => $value) {
                            if ($value === '') { unset($global[$key]); } else { $global[$key] = $value; }
                        }
                    } else {
                        $local = array_replace($local, $values);
                    }
                }
                continue;
            }
            if (!in_array($type, ["\0", '0', '5'], true) || $this->headerString(substr($header, 157, 100)) !== '') {
                throw new RuntimeException('Links, sparse files and special files are not supported in TAR archives.');
            }
            $attributes = array_replace($global, $local);
            $name = $attributes['path'] ?? $longName ?? $name;
            if (isset($attributes['size'])) { $size = $this->decimal($attributes['size']); }
            if (isset($attributes['mtime'])) { $mtime = $this->timestamp($attributes['mtime']); }
            $local = [];
            $longName = null;
            $kind = $type === '5' ? 'dir' : 'file';
            if (($kind === 'dir' && $size !== 0) || $size > $this->maxExpandedBytes - $expanded) {
                throw new RuntimeException('The TAR archive exceeds the expanded size limit or reports an invalid size.');
            }
            $expanded += $size;
            $alreadyRead = yield ['name' => $name, 'type' => $kind, 'size' => $size, 'mtime' => $mtime];
            $alreadyRead ??= 0;
            if (!is_int($alreadyRead) || $alreadyRead < 0 || $alreadyRead > $size || $alreadyRead > $byteBudget - $consumed) {
                throw new RuntimeException('The TAR member stream reported an invalid read length.');
            }
            $consumed += $alreadyRead;
            $this->skip($source, $size - $alreadyRead, $consumed, $byteBudget);
            $this->skip($source, (self::BLOCK - $size % self::BLOCK) % self::BLOCK, $consumed, $byteBudget);
        }
    }

    private function headerString(string $field): string {
        $zero = strpos($field, "\0");
        if ($zero === false) { return $field; }
        if (strspn(substr($field, $zero), "\0") !== strlen($field) - $zero) {
            throw new RuntimeException('The TAR archive contains an invalid header string.');
        }
        return substr($field, 0, $zero);
    }

    private function processedByteBudget(): int {
        // Payload plus bounded headers, extended metadata and terminal padding.
        $overhead = min($this->maxEntries, 1000000) * 2048 + min($this->maxIndexNameBytes, 16777216) + self::MAX_TRAILING_PADDING;
        return $this->maxExpandedBytes > PHP_INT_MAX - $overhead ? PHP_INT_MAX : $this->maxExpandedBytes + $overhead;
    }

    private function number(string $field, bool $allowBinary = true): int {
        if ($allowBinary && (ord($field[0]) & 0x80) !== 0) {
            if ((ord($field[0]) & 0x40) !== 0) {
                throw new RuntimeException('The TAR archive reports a negative binary size or timestamp.');
            }
            $value = ord($field[0]) & 0x7f;
            foreach (str_split(substr($field, 1)) as $digit) {
                if ($value > intdiv(PHP_INT_MAX - ord($digit), 256)) {
                    throw new RuntimeException('The TAR archive reports an overflowing number.');
                }
                $value = $value * 256 + ord($digit);
            }
            return $value;
        }
        $field = trim($field, " \0");
        if ($field === '') { return 0; }
        if (preg_match('/^[0-7]+$/D', $field) !== 1) {
            throw new RuntimeException('The TAR archive reports an invalid octal number.');
        }
        $value = 0;
        foreach (str_split($field) as $digit) {
            $part = ord($digit) - 48;
            if ($value > intdiv(PHP_INT_MAX - $part, 8)) {
                throw new RuntimeException('The TAR archive reports an overflowing number.');
            }
            $value = $value * 8 + $part;
        }
        return $value;
    }

    private function decimal(string $field): int {
        if (preg_match('/^[0-9]+$/D', $field) !== 1) {
            throw new RuntimeException('The TAR archive reports an invalid extended size.');
        }
        $value = 0;
        foreach (str_split($field) as $digit) {
            $part = ord($digit) - 48;
            if ($value > intdiv(PHP_INT_MAX - $part, 10)) {
                throw new RuntimeException('The TAR archive reports an overflowing extended number.');
            }
            $value = $value * 10 + $part;
        }
        return $value;
    }

    private function timestamp(string $value): int {
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new RuntimeException('The TAR archive reports an invalid extended timestamp.');
        }
        $negative = str_starts_with($value, '-');
        $seconds = explode('.', ltrim($value, '-'), 2)[0];
        $integer = $this->decimal($seconds);
        return $negative ? -$integer : $integer;
    }

    private function pax(string $data): array {
        $values = [];
        $position = 0;
        while ($position < strlen($data)) {
            $space = strpos($data, ' ', $position);
            if ($space === false || $space - $position > 8) {
                throw new RuntimeException('The TAR archive contains invalid PAX metadata.');
            }
            $length = $this->decimal(substr($data, $position, $space - $position));
            if ($length <= $space - $position + 2 || $length > strlen($data) - $position || $data[$position + $length - 1] !== "\n") {
                throw new RuntimeException('The TAR archive contains an incomplete PAX metadata record.');
            }
            $record = substr($data, $space + 1, $position + $length - $space - 2);
            $equal = strpos($record, '=');
            if ($equal === false || $equal === 0) {
                throw new RuntimeException('The TAR archive contains an invalid PAX metadata key.');
            }
            $key = substr($record, 0, $equal);
            if (str_contains(strtolower($key), 'sparse') || $key === 'linkpath' || $key === 'SCHILY.filetype') {
                throw new RuntimeException('Links, sparse files and special files are not supported in TAR archives.');
            }
            if (in_array($key, ['path', 'size', 'mtime'], true)) {
                if (isset($values[$key])) {
                    throw new RuntimeException('The TAR archive contains duplicate PAX metadata keys.');
                }
                $values[$key] = substr($record, $equal + 1);
            }
            $position += $length;
        }
        return $values;
    }

    /** @param resource $source */
    private function readExact(mixed $source, int $length, int &$consumed, int $budget): string {
        if ($length < 0 || $length > $budget - $consumed) {
            throw new RuntimeException('The TAR archive exceeds the processed byte limit.');
        }
        $data = '';
        while (strlen($data) < $length) {
            if (microtime(true) > $this->readDeadline) {
                throw new RuntimeException('Reading the TAR archive exceeded the time limit.');
            }
            $part = @fread($source, min(65536, $length - strlen($data)));
            if ($part === false || $part === '') {
                throw new RuntimeException('The TAR archive is incomplete or failed its integrity check.');
            }
            $data .= $part;
        }
        $consumed += $length;
        return $data;
    }

    /** @param resource $source */
    private function skip(mixed $source, int $length, int &$consumed, int $budget): void {
        while ($length > 0) {
            $amount = min(65536, $length);
            $this->readExact($source, $amount, $consumed, $budget);
            $length -= $amount;
        }
    }
}

/** @internal Counts actual source bytes, including PHP's stream read-ahead. */
final class TarSliceState {
    public int $position = 0;
}

/** @internal zlib streams otherwise silently accept a missing gzip footer. */
final class GzipTarStream {
    public mixed $context;
    private const SCHEME = 'archivetoolsgzip';
    private static array $pending = [];
    private mixed $source;
    private \InflateContext $inflater;
    private string $buffer = '';
    private int $position = 0;
    private int $produced = 0;
    private int $maxBytes;
    private float $deadline;
    private bool $complete = false;
    private ?string $failure = null;
    private ?OperationBudget $budget = null;

    /** @return resource */
    public static function wrap(string $path, int $maxBytes, ?OperationBudget $budget = null) {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true) && !stream_wrapper_register(self::SCHEME, self::class)) {
            throw new RuntimeException('The gzip read stream could not be registered.');
        }
        $token = bin2hex(random_bytes(16));
        self::$pending[$token] = compact('path', 'maxBytes', 'budget');
        try {
            $stream = fopen(self::SCHEME . '://' . $token, 'rb');
            if ($stream === false) { throw new RuntimeException('The gzip archive could not be opened.'); }
            return $stream;
        } finally { unset(self::$pending[$token]); }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
        $token = substr($path, strlen(self::SCHEME) + 3);
        if ($mode !== 'rb' || !isset(self::$pending[$token])) { return false; }
        $data = self::$pending[$token];
        unset(self::$pending[$token]);
        $source = @fopen($data['path'], 'rb');
        if ($source === false) { return false; }
        $inflater = inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflater === false) { fclose($source); return false; }
        $this->source = $source;
        $this->inflater = $inflater;
        $this->maxBytes = $data['maxBytes'];
        $this->budget = $data['budget'];
        $this->deadline = microtime(true) + 300;
        return true;
    }

    private function fill(int $wanted): void {
        if ($this->failure !== null) { throw new RuntimeException($this->failure); }
        while (strlen($this->buffer) < $wanted && !$this->complete) {
            $this->budget?->check();
            if (microtime(true) > $this->deadline) { $this->fail('Reading the gzip archive exceeded the time limit.'); }
            // A small input chunk bounds the temporary inflated output allocation.
            $packed = @fread($this->source, 1024);
            if ($packed === false || ($packed === '' && !feof($this->source))) {
                $this->fail('Reading the gzip archive failed.');
            }
            $before = inflate_get_read_len($this->inflater);
            $decoded = @inflate_add($this->inflater, $packed, $packed === '' ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($decoded === false || strlen($decoded) > $this->maxBytes - $this->produced) {
                $this->fail('The gzip archive is damaged or exceeds the expanded size limit.');
            }
            $this->produced += strlen($decoded);
            $this->buffer .= $decoded;
            if (inflate_get_status($this->inflater) === ZLIB_STREAM_END) {
                // Reject concatenated streams and hidden data; one gzip wraps one TAR.
                if (inflate_get_read_len($this->inflater) - $before !== strlen($packed) || @fread($this->source, 1) !== '' || !feof($this->source)) {
                    $this->fail('The gzip archive contains unsupported trailing compressed data.');
                }
                $this->complete = true;
            } elseif ($packed === '') {
                $this->fail('The gzip archive is incomplete or failed its integrity check.');
            }
        }
    }

    public function stream_read(int $count): string {
        $this->fill($count);
        $data = substr($this->buffer, 0, $count);
        $this->buffer = substr($this->buffer, strlen($data));
        $this->position += strlen($data);
        return $data;
    }

    public function stream_eof(): bool {
        if ($this->buffer === '') { $this->fill(1); }
        return $this->complete && $this->buffer === '';
    }

    private function fail(string $message): never {
        $this->failure = $message;
        throw new RuntimeException($message);
    }

    public function stream_tell(): int { return $this->position; }
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool { return false; }
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool { return false; }
    public function stream_close(): void {
        if (isset($this->source) && is_resource($this->source)) { fclose($this->source); }
    }
}

/** @internal A member slice; closes native decoders/processes when abandoned. */
final class TarMemberStream {
    public mixed $context;
    private const SCHEME = 'archivetoolstar';
    private static array $pending = [];
    private mixed $source;
    private int $size;
    private int $mtime;
    private int $position = 0;
    private ?string $failure = null;
    private ?TarSliceState $state = null;
    private bool $closeSource = true;

    /** @param resource $source @return resource */
    public static function wrap(mixed $source, int $size, int $mtime, ?TarSliceState $state = null, bool $closeSource = true) {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true) && !stream_wrapper_register(self::SCHEME, self::class)) {
            throw new RuntimeException('The TAR read stream could not be registered.');
        }
        $token = bin2hex(random_bytes(16));
        self::$pending[$token] = compact('source', 'size', 'mtime', 'state', 'closeSource');
        try {
            $result = fopen(self::SCHEME . '://' . $token, 'rb');
            if ($result === false) { throw new RuntimeException('The TAR read stream could not be opened.'); }
            return $result;
        } finally {
            unset(self::$pending[$token]);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
        $token = substr($path, strlen(self::SCHEME) + 3);
        if ($mode !== 'rb' || !isset(self::$pending[$token])) { return false; }
        $data = self::$pending[$token];
        unset(self::$pending[$token]);
        $this->source = $data['source'];
        $this->size = $data['size'];
        $this->mtime = $data['mtime'];
        $this->state = $data['state'];
        $this->closeSource = $data['closeSource'];
        return true;
    }

    public function stream_read(int $count): string {
        if ($this->failure !== null) { throw new RuntimeException($this->failure); }
        $remaining = $this->size - $this->position;
        if ($remaining === 0) { return ''; }
        $data = @fread($this->source, min($count, $remaining));
        if ($data === false || $data === '') {
            $this->failure = 'The TAR member is incomplete or failed its integrity check.';
            throw new RuntimeException($this->failure);
        }
        $this->position += strlen($data);
        if ($this->state !== null) { $this->state->position = $this->position; }
        return $data;
    }

    public function stream_eof(): bool {
        if ($this->failure !== null) { throw new RuntimeException($this->failure); }
        return $this->position === $this->size;
    }

    public function stream_tell(): int { return $this->position; }
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool { return false; }
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool { return false; }

    public function stream_stat(): array {
        $values = [0, 0, 0100444, 1, 0, 0, 0, $this->size, $this->mtime, $this->mtime, $this->mtime, -1, -1];
        $keys = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime', 'blksize', 'blocks'];
        return $values + array_combine($keys, $values);
    }

    public function stream_close(): void {
        if ($this->closeSource && isset($this->source) && is_resource($this->source)) { fclose($this->source); }
    }
}
