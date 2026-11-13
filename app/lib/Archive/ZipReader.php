<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

use InvalidArgumentException;
use Normalizer;
use OutOfBoundsException;
use RuntimeException;
use SensitiveParameter;
use ZipArchive;

/**
 * Read-only ZIP access. Member names are never used as filesystem paths.
 * The index deliberately rejects ambiguous paths instead of losing entries.
 */
final class ZipReader {

	public const MAX_ENTRIES = 10000;
	public const MAX_EXPANDED_BYTES = 4294967296;
	public const MAX_INDEX_NAME_BYTES = 8388608;

	private const MAX_PATH_BYTES = 4000;
	private const MAX_COMPONENT_BYTES = 250;
	private readonly int $maxMemberBytes;

	public function __construct(
		private readonly int $maxEntries = self::MAX_ENTRIES,
		private readonly int $maxExpandedBytes = self::MAX_EXPANDED_BYTES,
		private readonly int $maxDepth = 32,
		private readonly int $maxIndexNameBytes = self::MAX_INDEX_NAME_BYTES,
		?int $maxMemberBytes = null,
	) {
		$this->maxMemberBytes = $maxMemberBytes ?? $maxExpandedBytes;
		if (PHP_INT_SIZE < 8 || $maxEntries < 1 || $maxExpandedBytes < 1 || $maxDepth < 1 || $maxIndexNameBytes < 1 || $this->maxMemberBytes < 1) {
			throw new RuntimeException('ZIP reader limits require positive values and 64-bit PHP.');
		}
	}

	public static function largeIndex(): self {
		return new self(
			maxEntries: 120000,
			maxExpandedBytes: 17179869184,
			maxIndexNameBytes: 33554432,
			maxMemberBytes: self::MAX_EXPANDED_BYTES,
		);
	}

	/**
	 * @return array{format: string, fileCount: int, directoryCount: int,
	 *   totalSize: int, compressedSize: int, encrypted: bool,
	 *   entries: array<string, array{type: string, originalName: ?string,
	 *     size: int, mtime: int, crc: ?int, encrypted: bool}>}
	 */
	public function inspect(string $localPath, #[SensitiveParameter] ?string $password = null, ?OperationBudget $budget = null): array {
		$budget?->check();
		$zip = $this->open($localPath, $password);
		try {
			if ($zip->numFiles > $this->maxEntries) {
				throw new RuntimeException('The ZIP archive contains too many entries.');
			}
			$entries = [];
			$rawPaths = [];
			$explicitPaths = [];
			$totalSize = 0;
			$compressedSize = 0;
			$fileCount = 0;
			$encrypted = false;
			$indexNameBytes = 0;
			for ($index = 0; $index < $zip->numFiles; $index++) {
				if ($index % 256 === 0) {
					$budget?->check();
				}
				$stat = $this->entryStat($zip, $index);
				$name = $stat['name'];
				[$path, $rawParts, $parts] = $this->normalizePath($name);
				$this->consumeNameBytes(strlen($name), $indexNameBytes);
				$isDirectory = str_ends_with(str_replace('\\', '/', $name), '/');
				if (isset($explicitPaths[$path])) {
					throw new RuntimeException('The ZIP archive contains duplicate or colliding entry names.');
				}
				$explicitPaths[$path] = true;
				foreach ($parts as $position => $part) {
					$prefix = implode('/', array_slice($parts, 0, $position + 1));
					$rawPrefix = implode('/', array_slice($rawParts, 0, $position + 1));
					if (isset($rawPaths[$prefix]) && $rawPaths[$prefix] !== $rawPrefix) {
						throw new RuntimeException('The ZIP archive contains names that collide after Unicode normalization.');
					}
					$rawPaths[$prefix] = $rawPrefix;
					if ($position < count($parts) - 1 || $isDirectory) {
						if (isset($entries[$prefix]) && $entries[$prefix]['type'] !== 'dir') {
							throw new RuntimeException('The ZIP archive uses the same path for a file and a directory.');
						}
						if (!isset($entries[$prefix])) {
							$this->consumeNameBytes(strlen($prefix), $indexNameBytes);
							$entries[$prefix] = $this->directoryEntry();
						}
						$this->checkIndexSize($entries);
					}
				}
				if ($isDirectory) {
					$entries[$path]['originalName'] = $name;
					continue;
				}
				if (isset($entries[$path])) {
					throw new RuntimeException('The ZIP archive uses the same path for a file and a directory.');
				}
				$entry = $this->fileEntry($stat, $totalSize, $compressedSize);
				$encrypted = $encrypted || $entry['encrypted'];
				$this->consumeNameBytes(strlen($path), $indexNameBytes);
				$entries[$path] = $entry;
				$this->checkIndexSize($entries);
				$fileCount++;
			}
			ksort($entries, SORT_STRING);
			$budget?->check();
			return [
				'format' => 'zip',
				'fileCount' => $fileCount,
				'directoryCount' => count($entries) - $fileCount,
				'totalSize' => $totalSize,
				'compressedSize' => $compressedSize,
				'encrypted' => $encrypted,
				'entries' => $entries,
			];
		} finally {
			$zip->close();
		}
	}

	/**
	 * Validate physical members while retaining only the requested folder,
	 * its ancestors and its immediate children for conflict detection.
	 * Use inspect() for strict validation of every branch of the archive.
	 *
	 * @throws InvalidArgumentException If the directory path is not canonical.
	 * @throws OutOfBoundsException If the directory does not exist.
	 */
	public function listDirectory(string $localPath, string $directory = '', #[SensitiveParameter] ?string $password = null, ?OperationBudget $budget = null): array {
		$budget?->check();
		$directoryParts = [];
		if ($directory !== '') {
			try {
				[$normalized, , $directoryParts] = $this->normalizePath($directory);
			} catch (RuntimeException $error) {
				throw new InvalidArgumentException('Invalid archive selection.', previous: $error);
			}
			if ($normalized !== $directory) {
				throw new InvalidArgumentException('Invalid archive selection.');
			}
		}
		$depth = count($directoryParts);
		$zip = $this->open($localPath, $password);
		try {
			if ($zip->numFiles > $this->maxEntries) {
				throw new RuntimeException('The ZIP archive contains too many entries.');
			}
			$entries = [];
			$totalSize = 0;
			$compressedSize = 0;
			$fileCount = 0;
			$indexNameBytes = 0;
			$passwordProbe = null;
			for ($index = 0; $index < $zip->numFiles; $index++) {
				if ($index % 256 === 0) {
					$budget?->check();
				}
				$stat = $this->entryStat($zip, $index);
				$name = $stat['name'];
				[$path, $rawParts, $parts] = $this->normalizePath($name);
				$this->consumeNameBytes(strlen($name), $indexNameBytes);
				$isDirectory = str_ends_with($name, '/') || str_ends_with($name, '\\');
				$file = null;
				if (!$isDirectory) {
					$file = $this->fileEntry($stat, $totalSize, $compressedSize);
					$this->consumeNameBytes(strlen($path), $indexNameBytes);
					$fileCount++;
					if ($passwordProbe === null && $file['encrypted']) {
						if ($zip->locateName($name) !== $index) {
							throw new RuntimeException('The ZIP archive contains duplicate or colliding entry names.');
						}
						$passwordProbe = $file;
					}
				}

				$prefix = '';
				$last = count($parts) - 1;
				for ($position = 0; $position <= min($depth, $last); $position++) {
					$part = $parts[$position];
					if ($position < $depth && $part !== $directoryParts[$position]) {
						break;
					}
					$prefix = $prefix === '' ? $part : $prefix . '/' . $part;
					$explicit = $position === $last;
					$this->rememberDirectoryMember(
						$entries, $prefix, $rawParts[$position], $index * ($this->maxDepth + 1) + $position + 1,
						$explicit ? $name : null, $explicit ? $file : null, $indexNameBytes,
					);
				}
				if ($isDirectory && $prefix !== $path) {
					$this->consumeNameBytes(strlen($path), $indexNameBytes);
				}
			}

			$folder = null;
			if ($depth > 0) {
				$folder = $entries[$directory] ?? null;
				if (($folder['type'] ?? null) !== 'dir') {
					throw new OutOfBoundsException('The requested folder is not present in the ZIP archive.');
				}
				unset($folder['rawPart'], $folder['explicit']);
				$prefix = '';
				foreach ($directoryParts as $part) {
					$prefix = $prefix === '' ? $part : $prefix . '/' . $part;
					unset($entries[$prefix]);
				}
			}
			foreach ($entries as &$entry) {
				unset($entry['rawPart'], $entry['explicit']);
			}
			unset($entry);
			ksort($entries, SORT_STRING);
			$budget?->check();
			return [
				'format' => 'zip',
				'directory' => $directory,
				'folder' => $folder,
				'entries' => $entries,
				'fileCount' => $fileCount,
				'totalSize' => $totalSize,
				'compressedSize' => $compressedSize,
				'encrypted' => $passwordProbe !== null,
				'passwordProbe' => $passwordProbe,
			];
		} finally {
			$zip->close();
		}
	}

	private function rememberDirectoryMember(array &$entries, string $path, string $rawPart, int $renderIndex, ?string $originalName, ?array $file, int &$indexNameBytes): void {
		$type = $file !== null ? 'file' : 'dir';
		if (isset($entries[$path])) {
			if ($entries[$path]['rawPart'] !== $rawPart) {
				throw new RuntimeException('The ZIP archive contains names that collide after Unicode normalization.');
			}
			if ($entries[$path]['explicit'] && $originalName !== null) {
				throw new RuntimeException('The ZIP archive contains duplicate or colliding entry names.');
			}
			if ($entries[$path]['type'] !== $type) {
				throw new RuntimeException('The ZIP archive uses the same path for a file and a directory.');
			}
			if ($originalName !== null) {
				$entries[$path]['explicit'] = true;
				$entries[$path]['originalName'] = $originalName;
			}
			return;
		}
		if ($file === null) {
			$this->consumeNameBytes(strlen($path), $indexNameBytes);
		}
		$entries[$path] = $file ?? $this->directoryEntry();
		$entries[$path]['rawPart'] = $rawPart;
		$entries[$path]['explicit'] = $originalName !== null;
		$entries[$path]['originalName'] = $originalName;
		$entries[$path]['renderIndex'] = $renderIndex;
		$this->checkIndexSize($entries);
	}

	private function entryStat(ZipArchive $zip, int $index): array {
		$stat = $zip->statIndex($index);
		if ($stat === false) {
			throw new RuntimeException('The ZIP archive contains an unreadable entry.');
		}
		$this->validateFileType($zip, $index);
		return $stat;
	}

	private function fileEntry(array $stat, int &$totalSize, int &$compressedSize): array {
		$size = $stat['size'];
		$packedSize = $stat['comp_size'];
		if (!is_int($size) || $size < 0 || !is_int($packedSize) || $packedSize < 0) {
			throw new RuntimeException('The ZIP archive reports an invalid entry size.');
		}
		if ($size > $this->maxExpandedBytes - $totalSize) {
			throw new RuntimeException('The ZIP archive exceeds the expanded size limit.');
		}
		if ($packedSize > PHP_INT_MAX - $compressedSize) {
			throw new RuntimeException('The ZIP archive reports an invalid compressed size.');
		}
		$totalSize += $size;
		$compressedSize += $packedSize;
		return [
			'type' => 'file',
			'originalName' => $stat['name'],
			'size' => $size,
			'mtime' => (int)$stat['mtime'],
			'crc' => (int)$stat['crc'],
			'encrypted' => ($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE,
		];
	}

	/** @return resource A non-seekable read stream which owns its ZIP handle. */
	public function stream(string $localPath, string $originalName, #[SensitiveParameter] ?string $password = null) {
		$zip = $this->open($localPath, $password);
		try {
			$this->normalizePath($originalName);
			$index = $zip->locateName($originalName);
			if ($index === false) {
				throw new RuntimeException('The requested file is not present in the ZIP archive.');
			}
			$this->validateFileType($zip, $index);
			$stat = $zip->statIndex($index);
			if ($stat === false || str_ends_with(str_replace('\\', '/', $originalName), '/')) {
				throw new RuntimeException('The requested ZIP member is not a readable file.');
			}
			if (!is_int($stat['size']) || $stat['size'] < 0 || $stat['size'] > $this->maxMemberBytes) {
				throw new RuntimeException('The ZIP member exceeds the expanded size limit.');
			}
			if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE && ($password === null || $password === '')) {
				throw new RuntimeException('A password is required to read this ZIP archive.');
			}
			$source = @$zip->getStreamIndex($index);
			if ($source === false) {
				throw new RuntimeException('Cannot read the ZIP member. The password may be incorrect, encryption unsupported, or the archive damaged.');
			}
			$crc = (int)$stat['crc'];
			$aes = in_array($stat['encryption_method'] ?? ZipArchive::EM_NONE, [
				ZipArchive::EM_AES_128, ZipArchive::EM_AES_192, ZipArchive::EM_AES_256,
			], true);
			// WinZip AE-2 stores zero instead of a CRC. libzip validates its AES
			// authentication code while reading; a zero CRC is still checked for
			// ordinary ZIP members. Nonzero AES CRCs (AE-1) are checked as well.
			$expectedCrc = $aes && $crc === 0 ? null : $crc;
			return ZipMemberStream::wrap($zip, $source, $stat['size'], $expectedCrc, (int)$stat['mtime']);
		} catch (\Throwable $error) {
			$zip->close();
			throw $error;
		}
	}

	private function open(string $localPath, #[SensitiveParameter] ?string $password): ZipArchive {
		if (!class_exists(ZipArchive::class) || !class_exists(Normalizer::class)) {
			throw new RuntimeException('ZIP archives require the PHP zip and intl extensions.');
		}
		if (!is_file($localPath) || !is_readable($localPath)) {
			throw new RuntimeException('The ZIP archive is not readable.');
		}
		$zip = new ZipArchive();
		if (@$zip->open($localPath, ZipArchive::RDONLY) !== true) {
			throw new RuntimeException('The file is not a readable ZIP archive.');
		}
		if ($password !== null && $password !== '' && !$zip->setPassword($password)) {
			$zip->close();
			throw new RuntimeException('The ZIP password could not be configured.');
		}
		return $zip;
	}

	/** @return array{string, list<string>, list<string>} */
	private function normalizePath(string $name): array {
		if ($name === '' || str_contains($name, "\0") || preg_match('//u', $name) !== 1) {
			throw new RuntimeException('The ZIP archive contains an invalid or unsupported filename.');
		}
		if (strlen($name) > self::MAX_PATH_BYTES) {
			throw new RuntimeException('A ZIP member path exceeds the maximum byte length.');
		}
		$name = str_replace('\\', '/', $name);
		if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
			throw new RuntimeException('The ZIP archive contains an absolute path.');
		}
		$rawParts = [];
		foreach (explode('/', $name) as $part) {
			if ($part === '..') {
				throw new RuntimeException('The ZIP archive contains a parent-directory path.');
			}
			if ($part !== '' && $part !== '.') {
				$rawParts[] = $part;
			}
		}
		if ($rawParts === [] || count($rawParts) > $this->maxDepth) {
			throw new RuntimeException('The ZIP archive contains an empty or excessively deep path.');
		}
		$parts = [];
		foreach ($rawParts as $part) {
			$normalized = Normalizer::normalize($part, Normalizer::FORM_C);
			if ($normalized === false) {
				throw new RuntimeException('The ZIP archive contains an invalid Unicode filename.');
			}
			if (strlen($normalized) > self::MAX_COMPONENT_BYTES) {
				throw new RuntimeException('A ZIP member filename exceeds the maximum byte length.');
			}
			$parts[] = $normalized;
		}
		$path = implode('/', $parts);
		if (strlen($path) > self::MAX_PATH_BYTES) {
			throw new RuntimeException('A normalized ZIP member path exceeds the maximum byte length.');
		}
		return [$path, $rawParts, $parts];
	}

	private function validateFileType(ZipArchive $zip, int $index): void {
		$opsys = 0;
		$attributes = 0;
		if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
			throw new RuntimeException('The ZIP archive contains unreadable file attributes.');
		}
		// Unix and macOS ZIP attributes contain a POSIX file type in the high word.
		if (in_array($opsys, [ZipArchive::OPSYS_UNIX, 19], true)) {
			$type = ($attributes >> 16) & 0170000;
			if (!in_array($type, [0, 0100000, 0040000], true)) {
				throw new RuntimeException('Symbolic links and special files are not supported in ZIP archives.');
			}
		}
	}

	private function directoryEntry(): array {
		return ['type' => 'dir', 'originalName' => null, 'size' => 0, 'mtime' => 0, 'crc' => null, 'encrypted' => false];
	}

	private function checkIndexSize(array $entries): void {
		if (count($entries) > $this->maxEntries) {
			throw new RuntimeException('The ZIP archive contains too many indexed files and directories.');
		}
	}

	/** Count each normalized index key and each original member name once. */
	private function consumeNameBytes(int $bytes, int &$total): void {
		if ($bytes > $this->maxIndexNameBytes - $total) {
			throw new RuntimeException('The ZIP archive exceeds the index filename size limit.');
		}
		$total += $bytes;
	}
}

/** @internal Owns the ZIP and verifies a fully consumed member stream. */
final class ZipMemberStream {

	public mixed $context;
	private const SCHEME = 'archivetoolszip';
	private static array $pending = [];
	private ZipArchive $zip;
	/** @var resource */
	private mixed $source;
	private int $size;
	private int $mtime;
	private ?string $crc;
	private \HashContext $hash;
	private int $position = 0;
	private bool $verified = false;
	private ?string $failure = null;

	/** @param resource $source @return resource */
	public static function wrap(ZipArchive $zip, mixed $source, int $size, ?int $crc, int $mtime) {
		if (!in_array(self::SCHEME, stream_get_wrappers(), true)
			&& !stream_wrapper_register(self::SCHEME, self::class)) {
			fclose($source);
			throw new RuntimeException('The ZIP read stream could not be registered.');
		}
		$token = bin2hex(random_bytes(16));
		self::$pending[$token] = compact('zip', 'source', 'size', 'crc', 'mtime');
		try {
			$result = fopen(self::SCHEME . '://' . $token, 'rb');
			if ($result === false) {
				throw new RuntimeException('The ZIP read stream could not be opened.');
			}
			return $result;
		} finally {
			if (isset(self::$pending[$token])) {
				fclose($source);
				unset(self::$pending[$token]);
			}
		}
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$token = substr($path, strlen(self::SCHEME) + 3);
		if (!isset(self::$pending[$token]) || $mode !== 'rb') {
			return false;
		}
		$data = self::$pending[$token];
		unset(self::$pending[$token]);
		$this->zip = $data['zip'];
		$this->source = $data['source'];
		$this->size = $data['size'];
		$this->mtime = $data['mtime'];
		$this->crc = $data['crc'] === null ? null : sprintf('%08x', $data['crc']);
		$this->hash = hash_init('crc32b');
		return true;
	}

	public function stream_read(int $count): string {
		if ($this->failure !== null) {
			throw new RuntimeException($this->failure);
		}
		if ($this->verified) {
			return '';
		}
		$data = @fread($this->source, $count);
		if ($data === false || ($data === '' && !feof($this->source))) {
			$this->fail('Reading the ZIP member failed. Check the password and archive integrity.');
		}
		$this->position += strlen($data);
		if ($this->position > $this->size) {
			$this->fail('The ZIP member exceeds its declared size.');
		}
		hash_update($this->hash, $data);
		if (feof($this->source)) {
			$this->verify();
		}
		return $data;
	}

	public function stream_eof(): bool {
		$eof = feof($this->source);
		if ($eof) {
			$this->verify();
		}
		return $eof;
	}

	private function verify(): void {
		if ($this->failure !== null) {
			throw new RuntimeException($this->failure);
		}
		if ($this->verified) {
			return;
		}
		// PHP marks a short ZIP read as EOF even when libzip has a deferred
		// authentication error. Force its final read before accepting EOF.
		$tail = @fread($this->source, 1);
		if ($tail === false || $tail !== '') {
			$this->fail('The ZIP member is incomplete or failed its integrity check.');
		}
		$actualCrc = hash_final($this->hash);
		if ($this->position !== $this->size || ($this->crc !== null && !hash_equals($this->crc, $actualCrc))) {
			$this->fail('The ZIP member is incomplete or failed its integrity check.');
		}
		$this->verified = true;
	}

	private function fail(string $message): never {
		$this->failure = $message;
		throw new RuntimeException($message);
	}

	public function stream_tell(): int {
		return $this->position;
	}

	public function stream_seek(int $offset, int $whence = SEEK_SET): bool {
		return false;
	}

	public function stream_set_option(int $option, int $arg1, ?int $arg2): bool {
		return false;
	}

	public function stream_stat(): array {
		$values = [0, 0, 0100444, 1, 0, 0, 0, $this->size, $this->mtime, $this->mtime, $this->mtime, -1, -1];
		$keys = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime', 'blksize', 'blocks'];
		return $values + array_combine($keys, $values);
	}

	public function stream_close(): void {
		if (isset($this->source) && is_resource($this->source)) {
			fclose($this->source);
		}
		if (isset($this->zip)) {
			$this->zip->close();
		}
	}
}
