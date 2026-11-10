<?php
declare(strict_types=1);

// Only the separate SQLite backup fixture is allowed to execute this helper.
if (PHP_SAPI !== 'cli' || !preg_match('/^[a-f0-9]{32}$/D', (string)getenv('ARCHIVE_TOOLS_BACKUP_FIXTURE'))) { exit(2); }
$offline = $argv[1] ?? '';
if (in_array($offline, ['offline-capture', 'offline-restore'], true)) {
    if (getenv('ARCHIVE_TOOLS_BACKUP_ROLE') !== 'copy') { exit(2); }
    function backupTree(string $root): array {
        $entries = [];
        foreach (['config', 'data'] as $part) {
            $base = $root . '/' . $part;
            if (!is_dir($base) || is_link($base)) { throw new RuntimeException('The private snapshot directory is missing or linked.'); }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            $paths = [$base];
            foreach ($iterator as $entry) { $paths[] = $entry->getPathname(); }
            foreach ($paths as $path) {
                $info = lstat($path);
                $key = substr($path, strlen($root) + 1);
                $item = ['mode' => $info['mode'], 'uid' => $info['uid'], 'gid' => $info['gid'], 'mtime' => $info['mtime']];
                if (is_link($path)) { $item['link'] = readlink($path); }
                elseif (is_file($path)) { $item['size'] = filesize($path); $item['sha256'] = hash_file('sha256', $path); }
                elseif (!is_dir($path)) { throw new RuntimeException('The private snapshot contains a special file.'); }
                $entries[$key] = $item;
            }
        }
        ksort($entries, SORT_STRING);
        return $entries;
    }
    function backupCopy(string $source, string $target): void {
        $process = proc_open(['/bin/cp', '-a', '--', $source, $target], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('The private snapshot copier could not start.'); }
        if (proc_close($process) !== 0) { throw new RuntimeException('The private snapshot copy failed.'); }
    }
    try {
        $token = (string)getenv('ARCHIVE_TOOLS_BACKUP_FIXTURE');
        $baseline = json_decode(file_get_contents('/var/www/html/data/.archive-tools-backup-fixture.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($baseline['backupFixtureToken'] ?? null) !== $token) { throw new RuntimeException('The source volume is not this private backup fixture.'); }
        if ($offline === 'offline-capture') {
            if (count(scandir('/snapshot')) !== 2) { throw new RuntimeException('The new private snapshot volume is not empty.'); }
            $expected = backupTree('/var/www/html');
            $database = $baseline['sqliteDatabaseRelativePath'] ?? '';
            if (!is_string($database) || !preg_match('~^data/[A-Za-z0-9_-]+\.db$~D', $database)
                || !isset($expected[$database]['sha256'])
                || file_get_contents('/var/www/html/' . $database, false, null, 0, 16) !== "SQLite format 3\0") {
                throw new RuntimeException('The coherent SQLite database file is missing.');
            }
            backupCopy('/var/www/html/config', '/snapshot/config');
            backupCopy('/var/www/html/data', '/snapshot/data');
            if (backupTree('/var/www/html') !== $expected || backupTree('/snapshot') !== $expected) { throw new RuntimeException('The stopped-instance snapshot differs from its source.'); }
            file_put_contents('/snapshot/.snapshot-manifest.json', json_encode(['token' => $token, 'entries' => $expected], JSON_THROW_ON_ERROR));
            chmod('/snapshot/.snapshot-manifest.json', 0600); chmod('/snapshot', 0700);
            $checks = [['name' => 'offline backup copies every config and data file with exact bytes ownership modes and modification times', 'passed' => true],
                ['name' => 'offline backup includes the SQLite database in the same stopped-instance snapshot', 'passed' => true]];
        } else {
            $manifest = json_decode(file_get_contents('/snapshot/.snapshot-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['token'] ?? null) !== $token) { throw new RuntimeException('The snapshot belongs to another fixture.'); }
            $expected = $manifest['entries'];
            if (backupTree('/snapshot') !== $expected) { throw new RuntimeException('The private snapshot was modified before restoration.'); }
            $rollback = '/var/www/html/.archive-tools-backup-rollback-' . $token;
            if (file_exists($rollback) || is_link($rollback) || !mkdir($rollback, 0700)) { throw new RuntimeException('The private rollback directory is unsafe.'); }
            if (!rename('/var/www/html/config', $rollback . '/config') || !rename('/var/www/html/data', $rollback . '/data')) { throw new RuntimeException('The private post-purge state could not be retained.'); }
            backupCopy('/snapshot/config', '/var/www/html/config');
            backupCopy('/snapshot/data', '/var/www/html/data');
            if (backupTree('/var/www/html') !== $expected) { throw new RuntimeException('The restored config and data differ from the coherent snapshot.'); }
            $checks = [['name' => 'offline restoration exactly replaces config data and SQLite DB from the same immutable snapshot', 'passed' => true],
                ['name' => 'post-purge mismatched state is retained separately instead of recursively deleting files', 'passed' => true]];
        }
        echo json_encode(['status' => 'passed', 'checks' => $checks, 'entryCount' => count($expected),
            'treeSha256' => hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    } catch (Throwable $error) {
        echo json_encode(['status' => 'failed', 'errorClass' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR) . "\n"; exit(1);
    }
}
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
\OC_App::registerAutoloading('archive_tools', '/var/www/html/custom_apps/archive_tools');
\OC_App::loadApps();
$server = \OC::$server;
$config = $server->get(\OCP\IConfig::class);
if ($config->getSystemValue('dbtype') !== 'sqlite3' || $config->getSystemValue('datadirectory') !== '/var/www/html/data') { exit(2); }

use OCA\ArchiveTools\AppInfo\Application;
use OCA\ArchiveTools\BackgroundJob\ExtractJob;
use OCA\ArchiveTools\Service\ApplicationCleanupService;
use OCA\ArchiveTools\Service\JobRepository;
use OCA\ArchiveTools\Service\LifecycleService;
use OCA\ArchiveTools\Service\MountRepository;
use OCA\ArchiveTools\Service\SettingsService;

$checks = [];
$action = $argv[1] ?? '';
$baselinePath = '/var/www/html/data/.archive-tools-backup-fixture.json';
$data = is_file($baselinePath) ? json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR) : null;
function backupCheck(string $name, bool $condition): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($name); }
    $checks[] = ['name' => $name, 'passed' => true];
}
function backupFolder(): \OCP\Files\Folder {
    global $server;
    $user = $server->get(\OCP\IUserManager::class)->get('fixture_admin');
    if ($user === null) { throw new RuntimeException('The private backup owner is unavailable.'); }
    $server->get(\OCP\IUserSession::class)->setUser($user);
    $server->get(\OCP\Files\ISetupManager::class)->setupForUser($user);
    return $server->get(\OCP\Files\IRootFolder::class)->getUserFolder('fixture_admin');
}
function backupFiles(array $baseline): void {
    $folder = backupFolder();
    $source = $folder->get('Lifecycle/source.zip');
    $output = $folder->get('Lifecycle/Extracted/kept.txt');
    backupCheck('restored source archive keeps its exact bytes and file id', $source->getId() === $baseline['fixture']['sourceId'] && hash('sha256', $source->getContent()) === $baseline['fixture']['sourceSha256']);
    backupCheck('restored user-edited output keeps its exact bytes and file id', $output->getId() === $baseline['fixture']['outputFileId'] && $output->getContent() === 'User-edited output must survive purge.');
}

try {
    $mounts = $server->get(MountRepository::class);
    $jobs = $server->get(JobRepository::class);
    $crypto = $server->get(\OCP\Security\ICrypto::class);
    $extra = [];
    if ($action === 'baseline') {
        $fixture = json_decode(file_get_contents('/tmp/archive-tools-lifecycle-fixture.json'), true, 512, JSON_THROW_ON_ERROR);
        $mount = $mounts->find($fixture['mountId']);
        $job = $jobs->find($fixture['queuedId']);
        backupCheck('baseline contains a pinned protected connection and queued protected task', (bool)$mount['pinned'] && $job['state'] === 'queued' && $mount['password'] !== null && $job['secret'] !== null);
        backupCheck('baseline credentials decrypt through the current instance key', $crypto->decrypt($mount['password']) === 'LifecycleArchive-Public-2026!' && $crypto->decrypt($job['secret']) === 'LifecycleArchive-Public-2026!');
        $data = ['backupFixtureToken' => (string)getenv('ARCHIVE_TOOLS_BACKUP_FIXTURE'),
            'sqliteDatabaseRelativePath' => 'data/' . (string)$config->getSystemValue('dbname') . '.db',
            'fixture' => $fixture, 'mountCiphertext' => $mount['password'], 'jobCiphertext' => $job['secret'],
            'indexSha256' => hash('sha256', $mount['index_json']), 'planSha256' => hash('sha256', $job['plan_json']),
            'settings' => $server->get(SettingsService::class)->getSettings(),
            'systemSecretSha256' => hash('sha256', (string)$config->getSystemValue('secret')),
            'instanceSha256' => hash('sha256', (string)$config->getSystemValue('instanceid')),
            'lifecycleState' => $server->get(LifecycleService::class)->state()];
        backupFiles($data);
        // The private baseline contains ciphertext, never an actual deployment
        // credential. It stays in the retained private fixture/backup volumes.
        file_put_contents($baselinePath, json_encode($data, JSON_THROW_ON_ERROR)); chmod($baselinePath, 0600);
    } elseif ($data === null) {
        throw new RuntimeException('The private coherent-backup baseline is missing.');
    } elseif ($action === 'purged') {
        backupCheck('purge really removed metadata before recovery', $mounts->find($data['fixture']['mountId']) === null && $jobs->find($data['fixture']['queuedId']) === null);
        backupCheck('purge really left the durable tombstone before recovery', $server->get(LifecycleService::class)->state() === 'purged');
        backupFiles($data);
    } elseif ($action === 'rotate-key') {
        $config->setSystemValue('secret', bin2hex(random_bytes(32)));
        backupCheck('negative control deliberately changes only the private instance encryption key', hash('sha256', (string)$config->getSystemValue('secret')) !== $data['systemSecretSha256']);
    } elseif ($action === 'wrong-key') {
        backupCheck('a fresh process observes the changed private config key', hash('sha256', (string)$config->getSystemValue('secret')) !== $data['systemSecretSha256']);
        $rejected = false;
        try { $rejected = $crypto->decrypt($data['mountCiphertext']) !== 'LifecycleArchive-Public-2026!'; }
        catch (Throwable) { $rejected = true; }
        backupCheck('restoring metadata without the original config key cannot recover the usable archive credential', $rejected);
    } elseif ($action === 'restored') {
        backupCheck('coherent restoration recovers the original instance identity', hash('sha256', (string)$config->getSystemValue('instanceid')) === $data['instanceSha256']);
        backupCheck('coherent restoration recovers the original config encryption key', hash('sha256', (string)$config->getSystemValue('secret')) === $data['systemSecretSha256']);
        backupCheck('coherent restoration recovers the ready lifecycle marker instead of the purge tombstone', $server->get(LifecycleService::class)->state() === $data['lifecycleState']);
        $mount = $mounts->find($data['fixture']['mountId']);
        $job = $jobs->find($data['fixture']['queuedId']);
        backupCheck('restored connection keeps its id pin and exact encrypted credential', $mount !== null && (bool)$mount['pinned'] && $mount['password'] === $data['mountCiphertext']);
        backupCheck('restored connection keeps its complete archive metadata', hash('sha256', $mount['index_json']) === $data['indexSha256']);
        backupCheck('restored task keeps its id queued state exact encrypted credential and plan', $job !== null && $job['state'] === 'queued' && $job['secret'] === $data['jobCiphertext'] && hash('sha256', $job['plan_json']) === $data['planSha256']);
        backupCheck('restored credentials decrypt without asking the user for a password', $crypto->decrypt($mount['password']) === 'LifecycleArchive-Public-2026!' && $crypto->decrypt($job['secret']) === 'LifecycleArchive-Public-2026!');
        backupCheck('restored administrator settings match the snapshot', $server->get(SettingsService::class)->getSettings() === $data['settings']);
        backupFiles($data);
        $folder = backupFolder();
        backupCheck('the restored virtual archive opens with its restored stored credential', $folder->get('Lifecycle/Connected/member.txt')->getContent() === 'Protected lifecycle fixture bytes.');
        $list = $server->get(\OCP\BackgroundJob\IJobList::class);
        $nativeId = null;
        foreach ($list->getJobsIterator(ExtractJob::class, null, 0) as $native) {
            if ((int)($native->getArgument()['jobId'] ?? 0) === $data['fixture']['queuedId']) { $nativeId = (string)$native->getId(); break; }
        }
        backupCheck('the restored native extraction queue registration is executable', $nativeId !== null && ctype_digit($nativeId));
        $extra['nativeJobId'] = $nativeId;
    } elseif ($action === 'extracted') {
        $job = $jobs->find($data['fixture']['queuedId']);
        backupCheck('the actual restored native worker completes its queued protected extraction', $job['state'] === 'succeeded' && (int)$job['files_done'] === 1);
        $folder = backupFolder();
        backupCheck('the restored worker extracts the correct plaintext without a password resubmission', $folder->get('Lifecycle/Queued output/member.txt')->getContent() === 'Protected lifecycle fixture bytes.');
        backupCheck('the completed restored task clears its credential', $job['secret'] === null && $job['active_key'] === null);
        backupFiles($data);
    } else { throw new RuntimeException('Unknown private backup fixture action.'); }
    echo json_encode(['checks' => $checks, 'status' => 'passed'] + $extra, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    echo json_encode(['checks' => $checks, 'status' => 'failed', 'errorClass' => $error::class,
        'message' => $error->getMessage(), 'file' => basename($error->getFile()), 'line' => $error->getLine()], JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}
