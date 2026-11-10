<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

use RuntimeException;
use SensitiveParameter;

/** Format dispatch is based on file bytes, including extensionless local copies. */
final class ArchiveReader {

    public function __construct(
        private readonly ZipReader $zip = new ZipReader(),
        private readonly TarReader $tar = new TarReader(),
        private readonly SevenZipReader $sevenZip = new SevenZipReader(),
    ) {}

    public function inspect(string $path, #[SensitiveParameter] ?string $password = null, ?OperationBudget $budget = null): array {
        $budget?->check();
        $format = $this->detect($path);
        $index = match ($format) {
            'zip' => $this->zip->inspect($path, $password),
            'tar', 'tar.gz', 'tar.bz2', 'tar.xz' => $this->tar->inspect($path, $password, $budget),
            default => $this->sevenZip->inspect($path, $password, $budget),
        };
        $budget?->check();
        return $index;
    }

    /** @return resource */
    public function stream(string $path, string $originalName, #[SensitiveParameter] ?string $password = null, ?OperationBudget $budget = null) {
        $budget?->check();
        $stream = match ($this->detect($path)) {
            'zip' => $this->zip->stream($path, $originalName, $password),
            'tar', 'tar.gz', 'tar.bz2', 'tar.xz' => $this->tar->stream($path, $originalName, $password, $budget),
            default => $this->sevenZip->stream($path, $originalName, $password, $budget),
        };
        return $budget === null ? $stream : BudgetStream::wrap($stream, $budget);
    }

    /** @return \Generator<string,resource> Consume/close a member before advancing. */
    public function streams(string $path, array $selectedEntries, #[SensitiveParameter] ?string $password = null, ?OperationBudget $budget = null): \Generator {
        $budget?->check();
        $format = $this->detect($path);
        if (in_array($format, ['tar', 'tar.gz', 'tar.bz2', 'tar.xz'], true)) {
            yield from $this->tar->streams($path, $selectedEntries, $password, $budget);
            return;
        }
        if (in_array($format, ['7z', 'rar'], true)) {
            yield from $this->sevenZip->streams($path, $selectedEntries, $password, $budget);
            return;
        }
        foreach ($selectedEntries as $member => $entry) {
            $budget?->check();
            if (($entry['type'] ?? null) === 'dir') { continue; }
            if (!is_string($entry['originalName'] ?? null)) { throw new RuntimeException('The selected archive entries are invalid or ambiguous.'); }
            $stream = $this->zip->stream($path, $entry['originalName'], $password);
            if ($budget !== null) { $stream = BudgetStream::wrap($stream, $budget); }
            try { yield (string)$member => $stream; }
            finally { if (is_resource($stream)) { fclose($stream); } }
        }
        $budget?->check();
    }

    /** @return list<string> */
    public function supportedFormats(): array {
        $formats = ['tar'];
        if (class_exists(\ZipArchive::class)) { array_unshift($formats, 'zip'); }
        if (function_exists('gzopen')) { $formats[] = 'tar.gz'; }
        if ($this->sevenZip->supports('bzip2')) { $formats[] = 'tar.bz2'; }
        if ($this->sevenZip->supports('xz')) { $formats[] = 'tar.xz'; }
        foreach (['7z', 'rar'] as $format) {
            if ($this->sevenZip->supports($format)) { $formats[] = $format; }
        }
        return $formats;
    }

    public static function baseName(string $filename): string {
        return preg_replace('/\.(?:tar\.(?:gz|bz2|xz)|tgz|tbz2?|txz|zip|tar|7z|rar)$/iD', '', $filename) ?? $filename;
    }

    public function detect(string $path): string {
        $source = @fopen($path, 'rb');
        if ($source === false) { throw new RuntimeException('The archive is not readable.'); }
        try { $head = fread($source, 512); } finally { fclose($source); }
        if (!is_string($head)) { throw new RuntimeException('The archive could not be read.'); }
        if (in_array(substr($head, 0, 4), ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) { return 'zip'; }
        if (str_starts_with($head, "\x1f\x8b")) { return 'tar.gz'; }
        if (str_starts_with($head, 'BZh')) { return 'tar.bz2'; }
        if (str_starts_with($head, "\xfd7zXZ\0")) { return 'tar.xz'; }
        if (str_starts_with($head, "7z\xbc\xaf\x27\x1c")) { return '7z'; }
        if (str_starts_with($head, "Rar!\x1a\x07\x00") || str_starts_with($head, "Rar!\x1a\x07\x01\x00")) { return 'rar'; }
        // A checksum-valid header is also required by TarReader. V7 tar has no magic.
        if (strlen($head) === 512 && (substr($head, 257, 5) === 'ustar' || $head === str_repeat("\0", 512) || preg_match('/^[0-7\x00 ]{8}$/D', substr($head, 148, 8)) === 1)) {
            return 'tar';
        }
        throw new RuntimeException('This archive format is not supported.');
    }
}
