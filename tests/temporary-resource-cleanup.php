<?php
declare(strict_types=1);

// Standalone: only uniquely created temporary fixtures, no Nextcloud bootstrap.
if (PHP_SAPI !== 'cli') { exit(2); }
$appPath = getenv('ARCHIVE_TOOLS_APP_PATH') ?: dirname(__DIR__) . '/app';
require $appPath . '/lib/Service/TemporaryResourceCleanup.php';
use OCA\ArchiveTools\Service\TemporaryResourceCleanup;

$root = sys_get_temp_dir() . '/archive-tools-temp-cleanup-test-' . bin2hex(random_bytes(16));
if (!mkdir($root, 0700)) { throw new RuntimeException('Cannot create the private cleanup fixture.'); }
$known = [];
$checks = 0;
$old = time() - 7200;
function temporaryCheck(string $name, bool $condition): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($name); }
    ++$checks; echo 'PASS ' . $name . "\n";
}
function temporaryFile(string $name, int $age, int $mode = 0600): string {
    global $root, $known;
    $path = $root . '/' . $name;
    file_put_contents($path, 'private fixture bytes'); chmod($path, $mode); touch($path, $age); $known[] = $path;
    return $path;
}
function temporaryAlias(int $age, string $kind = 'hardlink'): string {
    global $root, $known, $source;
    $path = $root . '/archive-tools-input-' . bin2hex(random_bytes(16));
    mkdir($path, 0700); $known[] = $path;
    if ($kind === 'symlink') { symlink($source, $path . '/archive.bin'); }
    else { link($source, $path . '/archive.bin'); }
    $known[] = $path . '/archive.bin'; touch($path, $age);
    return $path;
}
try {
    $source = temporaryFile('user-source.zip', time());
    $sourceBytes = file_get_contents($source);
    $oldHard = temporaryAlias($old);
    $oldSymbolic = temporaryAlias($old, 'symlink');
    $freshInput = temporaryAlias(time());
    $futureInput = temporaryAlias(time() + 7200);
    $oldNames = temporaryFile('archive-tools-names-Ab1234', $old);
    $freshNames = temporaryFile('archive-tools-names-Cd1234', time());
    $futureNames = temporaryFile('archive-tools-names-Ef1234', time() + 7200);
    $foreignName = temporaryFile('unrelated-private-list', $old);
    $wrongMode = temporaryFile('archive-tools-names-Gh1234', $old, 0644);
    $hardNames = $root . '/archive-tools-names-Ij1234'; link($source, $hardNames); $known[] = $hardNames;
    $linkedNames = $root . '/archive-tools-names-Kl1234'; symlink($source, $linkedNames); $known[] = $linkedNames;
    $ambiguous = temporaryAlias($old);
    $known[] = $ambiguous . '/unrelated.txt'; file_put_contents($ambiguous . '/unrelated.txt', 'retain'); touch($ambiguous, $old);
    $directoryTarget = $root . '/foreign-directory'; mkdir($directoryTarget, 0700); $known[] = $directoryTarget;
    $known[] = $directoryTarget . '/archive.bin'; file_put_contents($directoryTarget . '/archive.bin', 'never delete');
    $linkedDirectory = $root . '/archive-tools-input-' . bin2hex(random_bytes(16)); symlink($directoryTarget, $linkedDirectory); $known[] = $linkedDirectory;
    $cleaner = new TemporaryResourceCleanup($root);
    $snapshot = $cleaner->inspect();
    temporaryCheck('report distinguishes expired aliases from retained fresh and future aliases', $snapshot['expiredInputs'] === 2 && $snapshot['retainedFreshInputs'] === 2);
    temporaryCheck('report distinguishes expired name lists from retained fresh and future lists', $snapshot['expiredNameLists'] === 1 && $snapshot['retainedFreshNameLists'] === 2);
    temporaryCheck('report performs no deletion', is_file($oldNames) && is_dir($oldHard) && $snapshot['removedInputs'] === 0);
    $result = $cleaner->cleanup();
    temporaryCheck('expired owned hardlink alias is removed', !file_exists($oldHard) && $result['removedInputs'] === 2);
    temporaryCheck('expired owned symlink alias is removed without following its target', !is_link($oldSymbolic . '/archive.bin') && !file_exists($oldSymbolic));
    temporaryCheck('source archive bytes are preserved after unlinking either alias type', file_get_contents($source) === $sourceBytes);
    temporaryCheck('expired owned regular name-list file is removed', !file_exists($oldNames) && $result['removedNameLists'] === 1);
    temporaryCheck('fresh input alias is retained', is_dir($freshInput));
    temporaryCheck('future-dated input alias is retained', is_dir($futureInput));
    temporaryCheck('fresh name list is retained', is_file($freshNames));
    temporaryCheck('future-dated name list is retained', is_file($futureNames));
    temporaryCheck('unrelated temporary file is retained', is_file($foreignName));
    temporaryCheck('wrong-mode list is retained', is_file($wrongMode));
    temporaryCheck('hardlinked name list is retained', is_file($hardNames));
    temporaryCheck('symlinked name list is retained', is_link($linkedNames));
    temporaryCheck('ambiguous directory with an unrelated child is retained', is_file($ambiguous . '/unrelated.txt'));
    temporaryCheck('directory symlink and its foreign target are retained', is_link($linkedDirectory) && file_get_contents($directoryTarget . '/archive.bin') === 'never delete');
    temporaryCheck('repeat cleanup is idempotent', $cleaner->cleanup()['removedInputs'] === 0 && $cleaner->cleanup()['removedNameLists'] === 0);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $foreignOwned = temporaryFile('archive-tools-names-Mn1234', $old);
        chown($foreignOwned, 65534);
        $foreignInput = temporaryAlias($old);
        chown($foreignInput, 65534);
        $cleaner->cleanup();
        temporaryCheck('foreign-owner matching name list is retained', is_file($foreignOwned));
        temporaryCheck('foreign-owner matching alias directory is retained', is_dir($foreignInput));
    }
    for ($i = 0; $i < 205; ++$i) { temporaryFile('archive-tools-names-' . sprintf('%06d', $i), $old); }
    $batch = $cleaner->cleanup();
    temporaryCheck('one cleanup deletes at most two hundred owned resources', $batch['removedNameLists'] === 200 && $batch['deletionLimitReached']);
    temporaryCheck('the next cleanup drains the remaining expired resource batch', $cleaner->cleanup()['removedNameLists'] === 5);
    echo 'All ' . $checks . " temporary resource cleanup tests passed.\n";
} finally {
    // Delete only explicitly recorded fixture entries, never recurse/follow links.
    foreach (array_reverse($known) as $path) {
        if (is_link($path) || is_file($path)) { unlink($path); }
        elseif (is_dir($path)) { rmdir($path); }
    }
    rmdir($root);
}
