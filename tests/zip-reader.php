<?php

declare(strict_types=1);

use OCA\ArchiveTools\Archive\ArchiveReader;
use OCA\ArchiveTools\Archive\OperationBudget;
use OCA\ArchiveTools\Archive\OperationBudgetExceededException;
use OCA\ArchiveTools\Archive\ZipReader;

$appPath = getenv('ARCHIVE_TOOLS_APP_PATH') ?: dirname(__DIR__) . '/app';
spl_autoload_register(static function (string $class) use ($appPath): void {
	$prefix = 'OCA\\ArchiveTools\\Archive\\';
	if (str_starts_with($class, $prefix)) {
		require_once $appPath . '/lib/Archive/' . substr($class, strlen($prefix)) . '.php';
	}
});

if (!extension_loaded('zip') || !extension_loaded('intl')) {
	fwrite(STDERR, "The tests require PHP zip and intl.\n");
	exit(1);
}

$workDirectory = sys_get_temp_dir() . '/archive-tools-reader-' . bin2hex(random_bytes(8));
if (!mkdir($workDirectory, 0700)) {
	throw new RuntimeException('Unable to create the test directory.');
}
$createdFiles = [];
$passed = 0;

function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function rejects(callable $operation, string $description): void {
	try {
		$operation();
	} catch (RuntimeException) {
		return;
	}
	throw new RuntimeException('Expected rejection: ' . $description);
}

/** @param array<string,string> $members */
function makeZip(array $members, ?callable $modify = null): string {
	global $workDirectory, $createdFiles;
	$path = $workDirectory . '/' . count($createdFiles) . '.zip';
	$createdFiles[] = $path;
	if ($members === []) {
		file_put_contents($path, pack('VvvvvVVv', 0x06054b50, 0, 0, 0, 0, 0, 0, 0));
		return $path;
	}
	$zip = new ZipArchive();
	check($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Create ZIP fixture');
	foreach ($members as $name => $content) {
		check($zip->addFromString($name, $content), 'Add ZIP fixture member');
	}
	if ($modify !== null) {
		$modify($zip);
	}
	check($zip->close(), 'Close ZIP fixture');
	return $path;
}

function test(string $name, callable $operation): void {
	global $passed;
	$operation();
	$passed++;
	echo 'PASS ' . $name . "\n";
}

try {
	test('normal index and directory inference', function (): void {
		$zip = makeZip(['reports/nested/a.txt' => 'alpha', 'b.txt' => 'beta'], function (ZipArchive $zip): void {
			$zip->addEmptyDir('empty');
		});
		$index = (new ZipReader())->inspect($zip);
		check($index['fileCount'] === 2, 'Count actual files');
		check($index['directoryCount'] === 3, 'Count inferred and explicit directories');
		check($index['totalSize'] === 9, 'Sum expanded bytes');
		check($index['entries']['reports/nested']['type'] === 'dir', 'Nested directory is inferred');
		check($index['entries']['empty']['size'] === 0 && $index['entries']['empty']['mtime'] === 0, 'Directory metadata');
	});

	test('stream survives reader destruction and validates file bytes', function (): void {
		$payload = str_repeat("Archive Tools\n", 2000);
		$zip = makeZip(['payload.txt' => $payload]);
		$reader = new ZipReader();
		$stream = $reader->stream($zip, 'payload.txt');
		unset($reader);
		try {
			check(fstat($stream)['size'] === strlen($payload), 'Stream reports the expanded size');
			check(stream_get_contents($stream) === $payload, 'Read all original bytes');
		} finally {
			fclose($stream);
		}
	});

	test('empty ZIP and empty member are readable', function (): void {
		$reader = new ZipReader();
		check($reader->inspect(makeZip([]))['entries'] === [], 'Valid empty ZIP');
		$stream = $reader->stream(makeZip(['empty.txt' => '']), 'empty.txt');
		try {
			check(stream_get_contents($stream) === '', 'Valid empty member');
		} finally {
			fclose($stream);
		}
	});

	test('empty password reads unencrypted ZIP exactly like an omitted password', function (): void {
		$payload = "HTML form download\0\xff\n";
		$zip = makeZip(['nested/member.bin' => $payload]);
		$reader = new ZipReader();
		check($reader->inspect($zip, '') === $reader->inspect($zip), 'Empty password preserves the complete archive index');
		foreach ([null, ''] as $password) {
			$stream = $reader->stream($zip, 'nested/member.bin', $password);
			try {
				check(stream_get_contents($stream) === $payload, 'Empty and omitted passwords return the original bytes');
			} finally {
				fclose($stream);
			}
		}
	});

	test('Unicode normalized lookup preserves raw member name', function (): void {
		$original = "Папка/Cafe\u{0301}-日本語-😀.txt";
		$zip = makeZip([$original => 'unicode']);
		$reader = new ZipReader();
		$index = $reader->inspect($zip);
		$member = $index['entries']["Папка/Caf\u{00E9}-日本語-😀.txt"];
		check($member['originalName'] === $original, 'Preserve stored member name');
		$stream = $reader->stream($zip, $member['originalName']);
		try {
			check(stream_get_contents($stream) === 'unicode', 'Read raw NFD member');
		} finally {
			fclose($stream);
		}
	});

	test('case-sensitive names stay distinct', function (): void {
		$index = (new ZipReader())->inspect(makeZip(['A.txt' => 'A', 'a.txt' => 'a']));
		check($index['fileCount'] === 2, 'Case-sensitive files retained');
	});

	test('Windows separators are normalized without changing raw lookup', function (): void {
		$zip = makeZip(['folder\\child.txt' => 'windows']);
		$reader = new ZipReader();
		$index = $reader->inspect($zip);
		check($index['entries']['folder/child.txt']['originalName'] === 'folder\\child.txt', 'Preserve backslash name');
		$stream = $reader->stream($zip, 'folder\\child.txt');
		try {
			check(stream_get_contents($stream) === 'windows', 'Read backslash member');
		} finally {
			fclose($stream);
		}
	});

	test('reject traversal and absolute paths', function (): void {
		foreach (['../outside.txt', 'folder/../outside.txt', '/absolute.txt', 'C:/absolute.txt', '\\server\\share.txt'] as $name) {
			$zip = makeZip([$name => 'unsafe']);
			rejects(fn () => (new ZipReader())->inspect($zip), $name);
		}
	});

	test('reject Unicode and normalized duplicate collisions', function (): void {
		rejects(fn () => (new ZipReader())->inspect(makeZip(["Cafe\u{0301}.txt" => 'one', "Caf\u{00E9}.txt" => 'two'])), 'NFC collision');
		rejects(fn () => (new ZipReader())->inspect(makeZip(['same.txt' => 'one', './same.txt' => 'two'])), 'Normalized duplicate');
		rejects(fn () => (new ZipReader())->inspect(makeZip(["Cafe\u{0301}/a.txt" => 'one', "Caf\u{00E9}/b.txt" => 'two'])), 'Directory NFC collision');
	});

	test('reject file-directory conflicts in either entry order', function (): void {
		rejects(fn () => (new ZipReader())->inspect(makeZip(['parent' => 'file', 'parent/child.txt' => 'child'])), 'File becomes directory');
		rejects(fn () => (new ZipReader())->inspect(makeZip(['parent/child.txt' => 'child', 'parent' => 'file'])), 'Directory becomes file');
	});

	test('reject symbolic links', function (): void {
		$zip = makeZip(['link' => '../outside'], function (ZipArchive $zip): void {
			check($zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16), 'Set symlink fixture attributes');
		});
		rejects(fn () => (new ZipReader())->inspect($zip), 'Symlink');
	});

	test('enforce count, inferred-directory, byte and depth limits', function (): void {
		rejects(fn () => (new ZipReader(maxEntries: 1))->inspect(makeZip(['a.txt' => 'a', 'b.txt' => 'b'])), 'Entry count');
		rejects(fn () => (new ZipReader(maxEntries: 2))->inspect(makeZip(['a/b/c.txt' => 'one'])), 'Inferred directory count');
		rejects(fn () => (new ZipReader(maxExpandedBytes: 4))->inspect(makeZip(['a.txt' => 'abc', 'b.txt' => 'de'])), 'Total expanded bytes');
		rejects(fn () => (new ZipReader(maxDepth: 1))->inspect(makeZip(['a/b.txt' => 'one'])), 'Depth');
	});

	test('list only immediate ZIP folder children with stable archive ordinals', function (): void {
		$zip = makeZip([
			'reports/nested/a.txt' => 'alpha',
			'reports/b.txt' => 'beta',
			'outside.txt' => 'other',
			'0/42' => 'numeric',
		], static function (ZipArchive $archive): void {
			check($archive->addEmptyDir('reports'), 'Add an explicit record after inferring its directory');
			check($archive->addEmptyDir('reports/empty'), 'Add an empty child directory');
		});
		$reader = new ZipReader();
		$root = $reader->listDirectory($zip);
		$reports = $reader->listDirectory($zip, 'reports');
		$nested = $reader->listDirectory($zip, 'reports/nested');
		$empty = $reader->listDirectory($zip, 'reports/empty');
		$numeric = $reader->listDirectory($zip, '0');
		check($root['folder'] === null && array_keys($root['entries']) === [0, 'outside.txt', 'reports'], 'Root exposes only its immediate children');
		check(array_keys($reports['entries']) === ['reports/b.txt', 'reports/empty', 'reports/nested'], 'Reports exposes only direct children in lexical order');
		check(array_keys($nested['entries']) === ['reports/nested/a.txt'] && $empty['entries'] === [], 'Nested and empty folders list correctly');
		check($reports['folder']['originalName'] === 'reports/' && $reports['folder'] === $root['entries']['reports'], 'An explicit record preserves the inferred folder ordinal');
		check($nested['folder'] === $reports['entries']['reports/nested'] && $empty['folder'] === $reports['entries']['reports/empty'], 'Folder ordinals stay stable while navigating');
		check($numeric['folder'] === $root['entries'][0] && $numeric['entries']['0/42']['size'] === 7, 'Numeric components preserve archive identity');
		$visible = $root['entries'] + $reports['entries'] + $nested['entries'] + $numeric['entries'];
		check(count(array_unique(array_column($visible, 'renderIndex'))) === count($visible), 'Every distinct path has a distinct ordinal');
		check($root['fileCount'] === 4 && $root['totalSize'] === 21 && $root['passwordProbe'] === null, 'Root retains archive-wide file totals');
	});

	test('reject ambiguous ZIP folder ancestors and children in both record orders', function (): void {
		$reader = new ZipReader();
		foreach ([['outer/sub/file' => '', 'outer' => ''], ['outer' => '', 'outer/sub/file' => '']] as $members) {
			$zip = makeZip($members);
			rejects(fn () => $reader->listDirectory($zip, 'outer/sub'), 'File and implied directory conflict at an ancestor');
		}
		foreach ([['outer/sub/file' => '', 'outer/sub' => ''], ['outer/sub' => '', 'outer/sub/file' => '']] as $members) {
			$zip = makeZip($members);
			rejects(fn () => $reader->listDirectory($zip, 'outer'), 'File and implied directory conflict at a child');
		}
		$alias = makeZip(['outer/sub' => '', 'outer/./sub' => '']);
		rejects(fn () => $reader->listDirectory($alias, 'outer'), 'Two explicit child paths normalize to the same path');
		$unicode = makeZip(["group/Caf\u{00E9}/first" => '', "group/Cafe\u{0301}/second" => '']);
		rejects(fn () => $reader->listDirectory($unicode, 'group'), 'NFC-colliding inferred siblings');
		rejects(fn () => $reader->listDirectory($unicode, "group/Caf\u{00E9}"), 'NFC-colliding target itself');
	});

	test('validate physical ZIP metadata even for members outside the selected folder', function (): void {
		$reader = new ZipReader();
		rejects(fn () => $reader->listDirectory(makeZip(['good/a' => '', 'outside/../b' => '']), 'good'), 'Off-branch traversal');
		$zip = makeZip(['good/a' => 'aaa', 'outside/b' => 'bb']);
		rejects(fn () => (new ZipReader(maxExpandedBytes: 4))->listDirectory($zip, 'good'), 'Off-branch declared expanded bytes');
		rejects(fn () => (new ZipReader(maxEntries: 1))->listDirectory($zip, 'good'), 'Off-branch physical record count');
		rejects(fn () => (new ZipReader(maxIndexNameBytes: 20))->listDirectory($zip, 'good'), 'Off-branch name bytes');
		$offBranchCollision = makeZip(['good/a' => '', 'outside/deep/one' => '', 'outside/deep/./one' => '']);
		$listing = $reader->listDirectory($offBranchCollision, 'good');
		check(array_keys($listing['entries']) === ['good/a'], 'Folder listing retains only conflicts relevant to the projected branch');
		rejects(fn () => $reader->inspect($offBranchCollision), 'Full inspection retains off-branch collision checks');
	});

	test('ZIP folder requests distinguish missing folders from invalid paths', function (): void {
		$reader = new ZipReader();
		$zip = makeZip(['a.txt' => 'a', 'folder/child.txt' => 'b']);
		foreach (['missing', 'a.txt', 'a.txt/child'] as $directory) {
			try {
				$reader->listDirectory($zip, $directory);
				throw new RuntimeException('Expected the requested folder to be missing.');
			} catch (OutOfBoundsException) {
			}
		}
		foreach (['../folder', '/folder', './folder', 'folder/', "Cafe\u{0301}", 'folder\\child'] as $directory) {
			try {
				$reader->listDirectory($zip, $directory);
				throw new RuntimeException('Expected the requested folder argument to be invalid.');
			} catch (InvalidArgumentException) {
			}
		}
	});

	test('ZIP folder listing identifies an exact encrypted password probe off branch', function (): void {
		$zip = makeZip(['plain/a' => 'visible', 'private/secret' => 'hidden'], static function (ZipArchive $archive): void {
			check($archive->setEncryptionName('private/secret', ZipArchive::EM_AES_256, 'fixture-password'), 'Set archive fixture password');
		});
		$reader = new ZipReader();
		$listing = $reader->listDirectory($zip, 'plain');
		check($listing['encrypted'] && $listing['passwordProbe']['originalName'] === 'private/secret', 'Password probe is available across folders');
		check(!$listing['entries']['plain/a']['encrypted'], 'Unencrypted child remains unencrypted');
	});

	test('use the larger ZIP index only with an explicit policy and configured reader', function (): void {
		$zip = makeZip(['a.txt' => 'abc', 'nested/b.txt' => 'de']);
		$strict = new ZipReader(maxEntries: 2, maxExpandedBytes: 4);
		$large = new ZipReader(maxEntries: 3, maxExpandedBytes: 5, maxMemberBytes: 4);
		$defaultReader = new ArchiveReader(zip: $strict);
		$optInReader = new ArchiveReader(zip: $strict, largeZip: $large);
		rejects(fn () => $defaultReader->inspect($zip, allowLargeZip: true), 'Opt-in must be configured');
		rejects(fn () => $optInReader->inspect($zip), 'Default inspection remains strict');
		$index = $optInReader->inspect($zip, allowLargeZip: true);
		check($index['fileCount'] === 2 && $index['directoryCount'] === 1 && $index['totalSize'] === 5, 'Larger ZIP index includes inferred directories and all declared bytes');
		rejects(fn () => $optInReader->inspect(makeZip(['../unsafe.txt' => '']), allowLargeZip: true), 'Larger ZIP index still validates paths');
		rejects(fn () => $optInReader->inspect(makeZip(["Cafe\u{0301}/a" => '', "Caf\u{00E9}/b" => '']), allowLargeZip: true), 'Larger ZIP index still rejects normalized name collisions');
	});

	test('bound ZIP member reads independently of the index total', function (): void {
		$zip = makeZip(['large.txt' => 'large', 'small.txt' => 'ok']);
		$reader = new ZipReader(maxExpandedBytes: 7, maxMemberBytes: 4);
		$index = $reader->inspect($zip);
		check($index['totalSize'] === 7, 'Index includes the expanded size of members larger than the stream limit');
		rejects(fn () => $reader->stream($zip, 'large.txt'), 'Separate member size limit');
		rejects(fn () => (new ZipReader(maxExpandedBytes: 4))->stream($zip, 'large.txt'), 'Default member limit follows a custom expanded-size constructor argument');
		$stream = $reader->stream($zip, 'small.txt');
		try {
			check(stream_get_contents($stream) === 'ok', 'A smaller member remains readable');
		} finally {
			fclose($stream);
		}
	});

	test('check the operation budget while iterating ZIP metadata', function (): void {
		$members = [];
		for ($number = 0; $number < 513; $number++) {
			$members["file-$number.txt"] = '';
		}
		$zip = makeZip($members);
		$ticks = 0;
		$budget = new OperationBudget(1, static function () use (&$ticks): float {
			return ++$ticks < 4 ? 0.0 : 1.0;
		});
		try {
			(new ZipReader())->inspect($zip, budget: $budget);
			throw new RuntimeException('Expected the budget to expire during ZIP inspection.');
		} catch (OperationBudgetExceededException) {
			check($ticks === 4, 'ZIP inspection checked the budget in the member loop');
		}
	});

	if (getenv('ARCHIVE_TOOLS_ZIP64_TEST') === '1') {
		test('inspect 85000 ZIP64 central directory records with the larger index', function (): void {
			$zip = makeZip(['payload.txt' => 'original bytes'], static function (ZipArchive $archive): void {
				for ($number = 1; $number < 85000; $number++) {
					$name = sprintf('departments/%02d/2026-11/reports/check-%05d.txt', $number % 100, $number);
					check($archive->addFromString($name, ''), 'Add ZIP64 fixture member');
				}
			});
			$reader = new ArchiveReader(largeZip: ZipReader::largeIndex());
			rejects(fn () => $reader->inspect($zip), 'The normal ZIP index rejects more than 10000 entries');
			memory_reset_peak_usage();
			$baseline = memory_get_usage(true);
			$started = hrtime(true);
			$index = $reader->inspect($zip, allowLargeZip: true);
			printf("ZIP64 inspection of %d files and %d directories took %.3f s (additional PHP allocator peak %.1f MiB)\n",
				$index['fileCount'], $index['directoryCount'], (hrtime(true) - $started) / 1e9, (memory_get_peak_usage(true) - $baseline) / 1048576);
			check($index['fileCount'] === 85000 && $index['directoryCount'] === 301 && $index['totalSize'] === 14, 'The large index includes every ZIP64 entry');
			unset($index);
			memory_reset_peak_usage();
			$baseline = memory_get_usage(true);
			$started = hrtime(true);
			$listing = ZipReader::largeIndex()->listDirectory($zip, 'departments');
			printf("ZIP64 folder listing of %d direct children took %.3f s (additional PHP allocator peak %.1f MiB)\n",
				count($listing['entries']), (hrtime(true) - $started) / 1e9, (memory_get_peak_usage(true) - $baseline) / 1048576);
			check($listing['fileCount'] === 85000 && count($listing['entries']) === 100, 'Large folder scan has complete totals and only direct children');
			$stream = $reader->stream($zip, 'payload.txt');
			try {
				check(stream_get_contents($stream) === 'original bytes', 'Read the original member bytes from the ZIP64 source');
			} finally {
				fclose($stream);
			}
		});
	}

	test('enforce native component byte limit after NFC normalization', function (): void {
		$reader = new ZipReader();
		$valid = str_repeat('a', 250);
		check(isset($reader->inspect(makeZip([$valid => '']))['entries'][$valid]), 'Accept the native 250-byte component');
		rejects(fn () => $reader->inspect(makeZip([str_repeat('a', 251) => ''])), 'Reject 251 ASCII characters before cache insertion');
		rejects(fn () => $reader->inspect(makeZip([str_repeat('a', 256) => ''])), 'Reject 256-byte component');
		rejects(fn () => $reader->inspect(makeZip([str_repeat('я', 128) => ''])), 'Measure UTF-8 bytes instead of character count');
		$validUnicode = str_repeat('я', 125);
		check(strlen($validUnicode) === 250 && isset($reader->inspect(makeZip([$validUnicode => '']))['entries'][$validUnicode]), 'Accept 250 UTF-8 bytes');
		rejects(fn () => $reader->inspect(makeZip([$validUnicode . 'a' => ''])), 'Reject 251 UTF-8 bytes despite fewer than 250 characters');
		$boundaryNfd = str_repeat('a', 248) . "e\u{0301}";
		$boundaryNfc = str_repeat('a', 248) . "\u{00E9}";
		check(strlen($boundaryNfd) === 251 && strlen($boundaryNfc) === 250
			&& isset($reader->inspect(makeZip([$boundaryNfd => '']))['entries'][$boundaryNfc]), 'Apply the byte boundary after NFC rather than before it');
		$nfd = str_repeat("e\u{0301}", 100);
		$nfc = str_repeat("\u{00E9}", 100);
		check(isset($reader->inspect(makeZip([$nfd => '']))['entries'][$nfc]), 'Measure normalized component, not decomposed bytes');
	});

	test('enforce full path byte length independently of component limits', function (): void {
		$prefix = str_repeat(str_repeat('a', 249) . '/', 15);
		$valid = $prefix . str_repeat('b', 250);
		check(strlen($valid) === 4000, 'Create exact path boundary fixture');
		$reader = new ZipReader();
		check(isset($reader->inspect(makeZip([$valid => '']))['entries'][$valid]), 'Accept 4000-byte path');
		$tooLong = str_repeat('a', 250) . '/' . str_repeat(str_repeat('a', 249) . '/', 14) . str_repeat('b', 250);
		rejects(fn () => $reader->inspect(makeZip([$tooLong => ''])), 'Reject 4001-byte path with otherwise valid components');
	});

	test('filename budget counts original names and inferred directory keys', function (): void {
		$zip = makeZip(['a/b.txt' => '']);
		// Original a/b.txt (7), inferred key a (1), normalized file key (7).
		check((new ZipReader(maxIndexNameBytes: 15))->inspect($zip)['fileCount'] === 1, 'Accept exact index name budget');
		rejects(fn () => (new ZipReader(maxIndexNameBytes: 14))->inspect($zip), 'Count inferred directory in filename budget');
		$nfd = "e\u{0301}.txt";
		// Raw NFD name is 7 bytes; NFC index key is 6 bytes.
		$unicode = makeZip([$nfd => '']);
		check((new ZipReader(maxIndexNameBytes: 13))->inspect($unicode)['fileCount'] === 1, 'Count raw and normalized bytes independently');
		rejects(fn () => (new ZipReader(maxIndexNameBytes: 12))->inspect($unicode), 'Enforce raw plus normalized name budget');
	});

	test('reject metadata-heavy archive at default eight MiB filename budget', function (): void {
		$prefix = str_repeat(str_repeat('a', 249) . '/', 15);
		$members = [];
		for ($index = 0; $index < 1100; $index++) {
			$members[$prefix . str_pad((string)$index, 250, 'b', STR_PAD_LEFT)] = '';
		}
		// Valid components/depth and zero expanded bytes; only metadata exceeds the budget.
		$zip = makeZip($members);
		rejects(fn () => (new ZipReader())->inspect($zip), 'Bound cumulative index name storage');
	});

	test('password-protected members report encryption and require the correct password', function (): void {
		$zip = makeZip(['secret.txt' => 'private bytes'], function (ZipArchive $zip): void {
			check($zip->setEncryptionName('secret.txt', ZipArchive::EM_AES_256, 'fixture-password'), 'Create encrypted fixture');
		});
		$reader = new ZipReader();
		check($reader->inspect($zip)['encrypted'], 'Report encrypted ZIP');
		check($reader->inspect($zip, '') === $reader->inspect($zip), 'Empty password permits protected metadata without opening protected members');
		rejects(fn () => $reader->stream($zip, 'secret.txt'), 'Missing password');
		rejects(fn () => $reader->stream($zip, 'secret.txt', ''), 'Empty password');
		rejects(function () use ($reader, $zip): void {
			$stream = $reader->stream($zip, 'secret.txt', 'wrong-fixture-password');
			try {
				stream_get_contents($stream);
			} finally {
				fclose($stream);
			}
		}, 'Wrong password');
		$stream = $reader->stream($zip, 'secret.txt', 'fixture-password');
		try {
			check(stream_get_contents($stream) === 'private bytes', 'Read decrypted bytes');
		} finally {
			fclose($stream);
		}
	});

	test('corrupt content cannot be read as success', function (): void {
		$payload = 'UNIQUE-STORED-PAYLOAD-90210';
		$zip = makeZip(['corrupt.txt' => $payload], function (ZipArchive $zip): void {
			check($zip->setCompressionName('corrupt.txt', ZipArchive::CM_STORE), 'Store uncompressed fixture');
		});
		$bytes = file_get_contents($zip);
		$position = strpos($bytes, $payload);
		check($position !== false, 'Find stored payload');
		$bytes[$position] = 'X';
		file_put_contents($zip, $bytes);
		rejects(function () use ($zip): void {
			$stream = (new ZipReader())->stream($zip, 'corrupt.txt');
			try {
				stream_get_contents($stream);
			} finally {
				fclose($stream);
			}
		}, 'Corrupt CRC');
	});

	test('AES authentication failure cannot be read as success without a stored CRC', function (): void {
		$zip = makeZip(['secret.txt' => 'private bytes'], function (ZipArchive $zip): void {
			check($zip->setEncryptionName('secret.txt', ZipArchive::EM_AES_256, 'fixture-password'), 'Create authenticated AES fixture');
		});
		$archive = new ZipArchive();
		check($archive->open($zip, ZipArchive::RDONLY) === true, 'Read AES fixture metadata');
		$stat = $archive->statIndex(0);
		check($stat !== false && $stat['crc'] === 0, 'Fixture uses AE-2 without a stored CRC');
		$archive->close();
		$bytes = file_get_contents($zip);
		$header = unpack('vnameLength/vextraLength', substr($bytes, 26, 4));
		$dataStart = 30 + $header['nameLength'] + $header['extraLength'];
		$authenticationByte = $dataStart + $stat['comp_size'] - 1;
		check($authenticationByte < strlen($bytes), 'Locate the final AES authentication byte');
		$bytes[$authenticationByte] = chr(ord($bytes[$authenticationByte]) ^ 1);
		file_put_contents($zip, $bytes);
		rejects(function () use ($zip): void {
			$stream = (new ZipReader())->stream($zip, 'secret.txt', 'fixture-password');
			try {
				stream_get_contents($stream);
			} finally {
				fclose($stream);
			}
		}, 'Damaged AES authentication code');
	});

	test('AES members with a stored CRC remain readable and verified', function (): void {
		$payload = str_repeat('private bytes', 4);
		$zip = makeZip(['secret.txt' => $payload], function (ZipArchive $zip): void {
			check($zip->setEncryptionName('secret.txt', ZipArchive::EM_AES_256, 'fixture-password'), 'Create AE-1 fixture');
		});
		$reader = new ZipReader();
		$index = $reader->inspect($zip);
		check($index['entries']['secret.txt']['crc'] !== 0, 'Fixture carries its plaintext CRC');
		$stream = $reader->stream($zip, 'secret.txt', 'fixture-password');
		try {
			check(stream_get_contents($stream) === $payload, 'Read AES member with stored CRC');
		} finally {
			fclose($stream);
		}
	});

	test('missing member and invalid ZIP give explicit failures', function (): void {
		$zip = makeZip(['present.txt' => 'present']);
		rejects(fn () => (new ZipReader())->stream($zip, 'missing.txt'), 'Missing member');
		file_put_contents($zip, 'not a ZIP');
		rejects(fn () => (new ZipReader())->inspect($zip), 'Invalid ZIP');
	});

	echo 'All ' . $passed . " ZIP reader tests passed.\n";
} finally {
	foreach ($createdFiles as $path) {
		if (is_file($path)) {
			unlink($path);
		}
	}
	rmdir($workDirectory);
}
