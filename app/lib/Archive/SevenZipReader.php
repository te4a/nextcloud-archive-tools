<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

/** Optional native 7-Zip backend. It never extracts members to filesystem paths. */
final class SevenZipReader {
    private ?string $binary = null;
    private ?array $formats = null;

    public function __construct(
        private readonly int $maxEntries = 10000,
        private readonly int $maxExpandedBytes = 4294967296,
        private readonly int $maxDepth = 32,
        private readonly int $maxIndexNameBytes = 8388608,
    ) {}

    public function available(): bool { return $this->binary() !== null; }

    private function binary(?OperationBudget $budget = null): ?string {
        if (!ProcessStream::available() || (!function_exists('link') && !function_exists('symlink'))) { return null; }
        if ($this->binary !== null) { return $this->binary; }

        $bundled = $this->bundledBinary();
        if ($bundled !== null) {
            // A file can pass is_executable() and still fail to start, for
            // example under a mount policy or with an incompatible ELF loader.
            $formats = $this->probeFormats($bundled, $budget);
            if ($formats['7z'] ?? false) {
                $this->formats = $formats;
                return $this->binary = $bundled;
            }
        }

        foreach (['/usr/local/bin/7zz', '/usr/bin/7zz', '/usr/local/bin/7z', '/usr/bin/7z'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) { return $this->binary = $candidate; }
        }
        return null;
    }

    private function bundledBinary(): ?string {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('php_uname')) { return null; }
        $name = match (php_uname('m')) {
            'x86_64' => '7zz-linux-x64',
            'aarch64' => '7zz-linux-arm64',
            default => null,
        };
        if ($name === null) { return null; }

        $directory = dirname(__DIR__, 2) . '/bin';
        if (is_link($directory) || !is_dir($directory)) { return null; }
        $candidate = $directory . '/' . $name;
        $stat = @lstat($candidate);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
            return null;
        }
        if (!is_executable($candidate)) {
            // Nextcloud's application installer copies contents and may drop
            // executable bits. Repair only this known, regular bundled file.
            if (!function_exists('chmod') || !@chmod($candidate, 0755)) { return null; }
            clearstatcache(true, $candidate);
        }
        return is_executable($candidate) ? $candidate : null;
    }

    public function supports(string $format, ?OperationBudget $budget = null): bool {
        $budget?->check();
        $binary = $this->binary($budget);
        if ($binary === null) { return false; }
        $this->formats ??= $this->probeFormats($binary, $budget);
        return $this->formats[$format] ?? false;
    }

    private function probeFormats(string $binary, ?OperationBudget $budget): array {
        try {
            $listing = ProcessStream::capture([$binary, 'i'], null, 262144, budget: $budget);
        } catch (OperationBudgetExceededException $error) {
            throw $error;
        } catch (\Throwable) {
            return [];
        }

        $formats = [];
        foreach (['7z' => '7z', 'rar' => 'Rar', 'bzip2' => 'bzip2', 'xz' => 'xz'] as $id => $label) {
            $formats[$id] = preg_match('/^\s*(?:\d+\s+)?\S+\s+' . preg_quote($label, '/') . '\s/m', $listing) === 1;
        }
        // Some distro builds can list RAR headers but omit its decoder.
        $formats['rar'] = $formats['rar'] && preg_match('/^.*\bD\b.*\bRar[135]\s*$/m', $listing) === 1;
        return $formats;
    }

    private function command(string $operation, string $path, ?string $type = null): array {
        if (!$this->available()) { throw new \RuntimeException('This archive format requires system 7-Zip, util-linux and PHP proc_open.'); }
        $command = [$this->binary(), $operation, '-bd', '-bsp0', '-bse2', '-sccUTF-8', '-spd', '-ssc', '-mmt2'];
        if ($operation === 'l') { array_push($command, '-slt', '-ba'); }
        else { array_push($command, '-so', '-bso0'); }
        if ($type !== null) { $command[] = '-t' . $type; }
        $command[] = '--';
        $command[] = $path;
        return $command;
    }

    private function format(string $path): string {
        $handle = @fopen($path, 'rb');
        if ($handle === false) { throw new \RuntimeException('The archive is not readable.'); }
        try { $magic = fread($handle, 128); } finally { fclose($handle); }
        if (str_starts_with($magic, "7z\xBC\xAF\x27\x1C")) { return '7z'; }
        if (str_starts_with($magic, "Rar!\x1A\x07\x01\x00")) {
            $offset = 12; // Signature and CRC32 precede the bounded main header.
            $this->vint($magic, $offset); // Header byte size.
            $type = $this->vint($magic, $offset);
            $flags = $this->vint($magic, $offset);
            if ($type === 1) {
                if ($flags & 1) { $this->vint($magic, $offset); }
                if ($flags & 2) { $this->vint($magic, $offset); }
                if ($this->vint($magic, $offset) & 1) { throw new \RuntimeException('Multipart archives are not supported.'); }
            }
            // An encrypted RAR5 main header cannot be examined here. Its child
            // still sees only an isolated alias; member volume fields are also
            // rejected after decryption, before metadata is published.
            return 'rar5';
        }
        if (str_starts_with($magic, "Rar!\x1A\x07\x00")) {
            if (strlen($magic) < 14 || ord($magic[9]) !== 0x73) { throw new \RuntimeException('The file is not a supported archive.'); }
            if (unpack('v', substr($magic, 10, 2))[1] & 1) { throw new \RuntimeException('Multipart archives are not supported.'); }
            return 'rar';
        }
        throw new \RuntimeException('The file is not a supported archive.');
    }

    private function vint(string $bytes, int &$offset): int {
        $value = 0;
        for ($index = 0; $index < 9; ++$index) {
            if ($offset >= strlen($bytes)) { throw new \RuntimeException('The file is not a supported archive.'); }
            $byte = ord($bytes[$offset++]);
            $value |= ($byte & 0x7f) << ($index * 7);
            if (($byte & 0x80) === 0) { return $value; }
        }
        throw new \RuntimeException('The file is not a supported archive.');
    }

    public function inspect(string $path, #[\SensitiveParameter] ?string $password = null, ?OperationBudget $budget = null): array {
        $budget?->check();
        $type = $this->format($path);
        $format = $type === 'rar5' ? 'rar' : $type;
        if (!$this->supports($format, $budget)) { throw new \RuntimeException('This archive format requires system 7-Zip, util-linux and PHP proc_open.'); }
        $input = new IsolatedArchiveInput($path);
        $text = ProcessStream::capture($this->command('l', $input->path(), $type), $password, input: $input, budget: $budget);
        $builder = new EntryIndexBuilder($this->maxEntries, $this->maxExpandedBytes, $this->maxDepth, $this->maxIndexNameBytes);
        foreach ($this->metadata($text) as $values) {
            $budget?->check();
            $attributes = $values['Attributes'] ?? '';
            foreach (['Symbolic Link', 'Hard Link', 'Copy Link', 'Alternate Stream', 'Anti'] as $special) {
                if (isset($values[$special]) && !in_array($values[$special], ['', '-'], true)) {
                    throw new \RuntimeException('Symbolic links and special files are not supported in archives.');
                }
            }
            if (($values['Volume Index'] ?? '') !== ''
                || !in_array($values['Split Before'] ?? '-', ['', '-'], true)
                || !in_array($values['Split After'] ?? '-', ['', '-'], true)) {
                throw new \RuntimeException('Multipart archives are not supported.');
            }
            $isDirectory = ($values['Folder'] ?? '') === '+' || str_starts_with($attributes, 'D');
            if (preg_match('/ ([^ ]{10})$/D', $attributes, $mode) && !in_array($mode[1][0], ['-', 'd', '0'], true)) {
                throw new \RuntimeException('Symbolic links and special files are not supported in archives.');
            }
            $size = (int)$values['Size'];
            $mtime = isset($values['Modified']) ? (strtotime($values['Modified'] . ' UTC') ?: 0) : 0;
            $crc = $values['CRC'] ?? '';
            if ($crc !== '' && !preg_match('/^[0-9A-Fa-f]{8}$/D', $crc)) { throw new \RuntimeException('The archive contains ambiguous or unsupported entry metadata.'); }
            $builder->add($values['Path'], $isDirectory ? 'dir' : 'file', $size, $mtime, $crc === '' ? null : (int)hexdec($crc), ($values['Encrypted'] ?? '-') === '+');
        }
        $result = $builder->result($format, filesize($path));
        $this->verifyLiteralNames($path, $type, $password, $result['entries'], $budget);
        $budget?->check();
        return $result;
    }

    /** Parse one bounded record at a time, never arrays of all lines/records. */
    private function metadata(string $text): \Generator {
        $offset = 0;
        if (preg_match('/\A\r?\nEnter password(?: \(will not be echoed\))?:\r?\n/', $text, $prompt)) { $offset = strlen($prompt[0]); }
        $length = strlen($text);
        $records = 0;
        $lines = 0;
        $values = [];
        while ($offset < $length) {
            if (++$lines > $this->maxEntries * 66 + 2) { throw new \RuntimeException('The archive contains too many entries.'); }
            $end = strpos($text, "\n", $offset);
            $lineLength = ($end === false ? $length : $end) - $offset;
            if ($lineLength > 8192) { throw new \RuntimeException('The archive contains ambiguous or unsupported entry metadata.'); }
            $line = substr($text, $offset, $lineLength);
            $offset = $end === false ? $length : $end + 1;
            if (str_ends_with($line, "\r")) { $line = substr($line, 0, -1); }
            if ($line === '') {
                if ($values !== []) {
                    yield $this->metadataRecord($values, $records);
                    $values = [];
                }
                continue;
            }
            // Keep trailing spaces: "Block = " is a valid empty value.
            if (count($values) >= 64 || !preg_match('/^([A-Za-z][A-Za-z0-9 ]*) = (.*)$/D', $line, $match) || isset($values[$match[1]])) {
                throw new \RuntimeException('The archive contains ambiguous or unsupported entry metadata.');
            }
            $values[$match[1]] = $match[2];
        }
        if ($values !== []) { yield $this->metadataRecord($values, $records); }
    }

    private function metadataRecord(array $values, int &$records): array {
        if (++$records > $this->maxEntries) { throw new \RuntimeException('The archive contains too many entries.'); }
        if (!isset($values['Path'], $values['Size']) || !preg_match('/^(0|[1-9][0-9]*)$/D', $values['Size']) || strlen($values['Size']) > 10) {
            throw new \RuntimeException('The archive reports an invalid entry size.');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $values['Path']) || preg_match('//u', $values['Path']) !== 1) {
            throw new \RuntimeException('The archive contains ambiguous or unsupported entry metadata.');
        }
        return $values;
    }

    /** 7-Zip replaces control characters with underscores in console paths. */
    private function verifyLiteralNames(string $path, string $type, #[\SensitiveParameter] ?string $password, array $entries, ?OperationBudget $budget = null): void {
        if ($entries === []) { return; }
        $leaves = array_fill_keys(array_keys($entries), true);
        foreach ($entries as $name => $_entry) {
            $budget?->check();
            $parent = dirname((string)$name);
            while ($parent !== '.' && $parent !== '') {
                unset($leaves[$parent]);
                $parent = dirname($parent);
            }
        }
        $names = [];
        foreach ($leaves as $name => $_leaf) {
            $originalName = $entries[$name]['originalName'];
            if (!is_string($originalName)) { throw new \RuntimeException('The archive contains ambiguous or unsupported entry metadata.'); }
            $names[$originalName] = true;
        }
        $list = tempnam(sys_get_temp_dir(), 'archive-tools-names-');
        if (!is_string($list)) { throw new \RuntimeException('The archive member names could not be verified.'); }
        try {
            if (!chmod($list, 0600)) { throw new \RuntimeException('The archive member names could not be verified.'); }
            $content = implode("\n", array_keys($names)) . "\n";
            if (file_put_contents($list, $content) !== strlen($content)) { throw new \RuntimeException('The archive member names could not be verified.'); }
            $input = new IsolatedArchiveInput($path);
            $arguments = $this->command('l', $input->path(), $type);
            array_splice($arguments, array_search('--', $arguments, true), 0, ['-scsUTF-8', '-i@' . $list]);
            $selected = $this->metadata(ProcessStream::capture($arguments, $password, input: $input, budget: $budget));
            foreach ($selected as $record) {
                $budget?->check();
                if (!isset($names[$record['Path']])) { throw new \RuntimeException('The archive contains ambiguous or unsupported entry metadata.'); }
                unset($names[$record['Path']]);
            }
            if ($names !== []) { throw new \RuntimeException('The archive contains ambiguous or unsupported entry metadata.'); }
        } finally { unlink($list); }
    }

    /** @return resource */
    public function stream(string $path, string $originalName, #[\SensitiveParameter] ?string $password = null, ?OperationBudget $budget = null) {
        $index = $this->inspect($path, $password, $budget);
        foreach ($index['entries'] as $entry) {
            if ($entry['type'] === 'file' && $entry['originalName'] === $originalName) {
                return $this->openKnownMember($path, $this->format($path), $entry, $password, $budget);
            }
        }
        throw new \RuntimeException('The requested file is not present in the archive.');
    }

    /** @return \Generator<string,resource> Validate the whole selected plan before yielding. */
    public function streams(string $path, array $selectedEntries, #[\SensitiveParameter] ?string $password = null, ?OperationBudget $budget = null): \Generator {
        $index = $this->inspect($path, $password, $budget);
        $type = $this->format($path);
        $validated = [];
        foreach ($selectedEntries as $member => $entry) {
            $budget?->check();
            $actual = $index['entries'][$member] ?? null;
            if (!is_array($entry) || !is_array($actual)) { throw new \RuntimeException('The selected archive entries are invalid or ambiguous.'); }
            foreach (['originalName', 'type', 'size', 'crc', 'encrypted'] as $field) {
                if (!array_key_exists($field, $entry) || $entry[$field] !== $actual[$field]) {
                    throw new \RuntimeException('The selected archive entries are invalid or ambiguous.');
                }
            }
            if ($actual['type'] === 'file') { $validated[(string)$member] = $actual; }
        }
        foreach ($validated as $member => $entry) {
            $budget?->check();
            $stream = $this->openKnownMember($path, $type, $entry, $password, $budget);
            try { yield (string)$member => $stream; }
            finally { if (is_resource($stream)) { fclose($stream); } }
        }
        $budget?->check();
    }

    /** @return resource Only accepts metadata validated by inspect(). */
    private function openKnownMember(string $path, string $type, array $entry, #[\SensitiveParameter] ?string $password, ?OperationBudget $budget = null) {
        $budget?->check();
        if ($entry['encrypted'] && ($password === null || $password === '')) { throw new ArchivePasswordRequiredException(); }
        $input = new IsolatedArchiveInput($path);
        $arguments = $this->command('x', $input->path(), $type);
        $arguments[] = $entry['originalName'];
        return ProcessStream::open($arguments, $password, $this->maxExpandedBytes, $entry['size'], $entry['crc'], $input, $budget);
    }

    /** @return resource Sequential TAR bytes; never materialized on disk. */
    public function compressedTarStream(string $path, ?OperationBudget $budget = null) {
        $budget?->check();
        $handle = @fopen($path, 'rb');
        if ($handle === false) { throw new \RuntimeException('The archive is not readable.'); }
        try { $magic = fread($handle, 6); } finally { fclose($handle); }
        $type = str_starts_with($magic, 'BZh') ? 'bzip2' : (str_starts_with($magic, "\xFD7zXZ\x00") ? 'xz' : null);
        if ($type === null || !$this->supports($type, $budget)) { throw new \RuntimeException('This archive format requires system 7-Zip, util-linux and PHP proc_open.'); }
        $input = new IsolatedArchiveInput($path);
        return ProcessStream::open($this->command('x', $input->path(), $type), null, $this->maxExpandedBytes + 33554432, input: $input, budget: $budget);
    }
}
