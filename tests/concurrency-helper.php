<?php
declare(strict_types=1);

// Test-only CLI workers. No application route loads this helper.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$action = $argv[1] ?? '';
$owner = $argv[2] ?? '';
if (!preg_match('/^at_\d{14}_[a-f0-9]{6}_(alice|bob)$/', $owner)) { fwrite(STDERR, "Only generated concurrency test users are allowed.\n"); exit(2); }
$workerId = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT);
$workerToken = $argv[4] ?? '';
$workerMarker = null;
$workerPhase = null;

function concurrencyWorkerPath(string $owner, int $id, string $token): string {
    if ($id < 1 || !preg_match('/^[a-f0-9]{32}$/', $token)) { throw new RuntimeException('Invalid owned worker marker.'); }
    return sys_get_temp_dir() . '/archive-tools-concurrency-' . hash('sha256', $owner) . '-' . $id . '-' . $token . '.json';
}

function concurrencyMarker(array $details): void {
    global $workerMarker, $workerPhase, $owner, $workerId;
    if ($workerMarker === null) { return; }
    $workerPhase = $details['phase'];
    $temporary = $workerMarker . '.tmp.' . getmypid();
    $data = ['owner' => $owner, 'jobId' => $workerId, 'pid' => getmypid(), 'time' => time()] + $details;
    if (file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX) === false) { throw new RuntimeException('Cannot write the owned worker status.'); }
    chmod($temporary, 0600);
    if (!rename($temporary, $workerMarker)) { throw new RuntimeException('Cannot publish the owned worker status.'); }
}

function concurrencySafeError(Throwable $error): array {
    global $owner;
    $admin = (string)getenv('NEXTCLOUD_ADMIN_PASSWORD');
    return ['exceptionClass' => $error::class, 'sourceFile' => basename($error->getFile()),
        'sourceLine' => $error->getLine(), 'message' => str_replace([$admin, hash('sha256', $admin . '::' . $owner)], '[redacted]', substr($error->getMessage(), 0, 500))];
}

function concurrencyMatchesPid(int $pid, string $owner, int $id, string $token): bool {
    $command = @file_get_contents('/proc/' . $pid . '/cmdline');
    $arguments = $command === false ? [] : array_values(array_filter(explode("\0", $command), static fn ($arg) => $arg !== ''));
    return count($arguments) === 6 && basename($arguments[0]) === 'php'
        && $arguments[1] === '/opt/archive-tools-tests/concurrency-helper.php'
        && $arguments[2] === 'run' && $arguments[3] === $owner
        && $arguments[4] === (string)$id && $arguments[5] === $token;
}

// Readiness and cleanup deliberately work before Nextcloud bootstrap and do
// not depend on stdout emitted by the worker or on a database connection.
if ($action === 'worker-status' || $action === 'worker-cleanup') {
    try {
        if ($workerId === false) { throw new RuntimeException('A test job id is required.'); }
        $path = concurrencyWorkerPath($owner, $workerId, $workerToken);
        $marker = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : null;
        if ($marker !== null && (($marker['owner'] ?? null) !== $owner || (int)($marker['jobId'] ?? 0) !== $workerId)) { throw new RuntimeException('The worker marker is not owned by this fixture.'); }
        $pid = (int)($marker['pid'] ?? 0);
        $alive = $pid > 1 && posix_kill($pid, 0);
        if ($action === 'worker-cleanup') {
            if ($alive) { throw new RuntimeException('An active worker marker cannot be removed.'); }
            if (is_file($path)) { unlink($path); }
            foreach (glob($path . '.tmp.*') as $temporary) { unlink($temporary); }
            echo json_encode(['removed' => true], JSON_THROW_ON_ERROR) . "\n";
        } else {
            echo json_encode(['marker' => $marker, 'alive' => $alive,
                'pidMatches' => $alive && concurrencyMatchesPid($pid, $owner, $workerId, $workerToken)], JSON_THROW_ON_ERROR) . "\n";
        }
        exit(0);
    } catch (Throwable $error) { fwrite(STDERR, json_encode(concurrencySafeError($error), JSON_THROW_ON_ERROR) . "\n"); exit(1); }
}
if ($action === 'run') {
    if ($workerId === false) { fwrite(STDERR, "A test job id is required.\n"); exit(2); }
    $workerMarker = concurrencyWorkerPath($owner, $workerId, $workerToken);
    concurrencyMarker(['phase' => 'booting']);
    register_shutdown_function(static function (): void {
        global $workerPhase;
        if (!in_array($workerPhase, ['finished', 'failed'], true)) {
            $last = error_get_last();
            concurrencyMarker(['phase' => 'stopped', 'interruptedPhase' => $workerPhase,
                'phpErrorType' => $last['type'] ?? null, 'sourceFile' => isset($last['file']) ? basename($last['file']) : null,
                'sourceLine' => $last['line'] ?? null]);
        }
    });
}
define('OC_CONSOLE', true);
try {
    require '/var/www/html/lib/base.php';
    \OC_App::loadApps();
} catch (Throwable $error) {
    $safe = concurrencySafeError($error);
    concurrencyMarker(['phase' => 'failed'] + $safe);
    fwrite(STDERR, json_encode($safe, JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}

use Icewind\Streams\CallbackWrapper;
use OCA\ArchiveTools\BackgroundJob\ExtractJob;
use OCA\ArchiveTools\Service\JobRepository;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Lock\ILockingProvider;

final class ConcurrencySlowStorage extends \OC\Files\Storage\Wrapper\Wrapper {
    public function fopen(string $path, string $mode) {
        $stream = parent::fopen($path, $mode);
        if (is_resource($stream) && str_starts_with($path, 'files/Concurrency/')
            && str_ends_with($path, '/large.bin') && strpbrk($mode, 'waxc+') !== false) {
            return CallbackWrapper::wrap($stream, null, static function (string $data): void {
                // A real native storage write, slowed only for deterministic
                // mid-file observation. No production reader or job is changed.
                usleep(1000);
            });
        }
        return $stream;
    }
}

final class ConcurrencyLockKeys implements ILockingProvider {
    public array $keys = [];
    public function isLocked(string $path, int $type): bool { return false; }
    public function acquireLock(string $path, int $type, ?string $readablePath = null): void { $this->keys[$path] = true; }
    public function releaseLock(string $path, int $type): void {}
    public function changeLock(string $path, int $targetType): void {}
    public function releaseAll(): void {}
}

/** Expire only exact native keys derived from the verified dead fixture. */
function concurrencyExpireLocks(array $keys, string $owner, int $jobId, int $pid): array {
    if ($keys === [] || count($keys) > 16) { throw new RuntimeException('The owned lock allowlist has an invalid size.'); }
    foreach ($keys as $key) {
        if (!is_string($key) || preg_match('/^files\/[a-f0-9]{32}$/D', $key) !== 1) {
            throw new RuntimeException('A collected lock is outside the native file-key namespace.');
        }
    }
    $provider = \OC::$server->get(ILockingProvider::class);
    $result = ['lockingProvider' => $provider::class, 'allowlistedKeys' => count($keys),
        'databaseType' => \OC::$server->get(\OCP\IConfig::class)->getSystemValue('dbtype', 'unknown')];
    if ($provider instanceof \OC\Lock\DBLockingProvider) {
        $db = \OC::$server->get(\OCP\IDBConnection::class);
        $qb = $db->getQueryBuilder();
        $expired = $qb->delete('file_locks')->where($qb->expr()->in('key',
            $qb->createNamedParameter($keys, IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement();
        return $result + ['lockBackend' => 'database', 'expiredOwnLocks' => $expired];
    }
    if (!$provider instanceof \OC\Lock\MemcacheLockingProvider
        || ltrim((string)\OC::$server->get(\OCP\IConfig::class)->getSystemValue('memcache.locking', ''), '\\') !== \OC\Memcache\Redis::class) {
        throw new RuntimeException('The test supports only native database or Redis file locking.');
    }

    // Server constructs this provider with createLocking('lock'). Reading its
    // actual cache object preserves that exact instance/version namespace,
    // including profiler wrappers, without reconstructing or exposing a prefix.
    // Reflection never changes the provider or its local ownership counters.
    $property = new ReflectionProperty(\OC\Lock\MemcacheLockingProvider::class, 'memcache');
    $cache = $property->getValue($provider);
    if (!$cache instanceof \OCP\IMemcache) { throw new RuntimeException('The native locking cache is unavailable.'); }
    $canary = 'archive-tools-concurrency-canary/' . hash('sha256', $owner . ':' . $jobId . ':' . $pid);
    $canaryValue = bin2hex(random_bytes(16));
    if (!$cache->add($canary, $canaryValue, 60)) { throw new RuntimeException('The owned locking-cache canary already exists.'); }
    try {
        $observed = [];
        foreach ($keys as $key) {
            $value = $cache->get($key);
            if ($value === null || $value === 0) { continue; }
            if ($value !== 'exclusive' && !(is_int($value) && $value > 0)) {
                throw new RuntimeException('A native fixture lock has an unexpected value.');
            }
            $observed[$key] = $value;
        }
        $expired = 0;
        foreach ($observed as $key => $value) {
            // Never overwrite/remove a lock which changed after observation.
            if (!$cache->cad($key, $value)) { throw new RuntimeException('A fixture lock changed during simulated expiry.'); }
            ++$expired;
        }
        if ($cache->get($canary) !== $canaryValue) { throw new RuntimeException('An unrelated locking-cache key was changed.'); }
        return $result + ['lockBackend' => 'redis', 'expiredOwnLocks' => $expired, 'unrelatedCacheKeyPreserved' => true];
    } finally {
        $cache->cad($canary, $canaryValue);
    }
}

try {
$user = \OC::$server->get(\OCP\IUserManager::class)->get($owner);
if ($user === null) { throw new RuntimeException('The concurrency test user does not exist.'); }
$password = hash('sha256', getenv('NEXTCLOUD_ADMIN_PASSWORD') . '::' . $owner);
$jobs = \OC::$server->get(JobRepository::class);
$list = \OC::$server->get(IJobList::class);
$reply = [];

if ($action === 'diagnostics') {
    $file = \OC::$server->get(\OCP\IConfig::class)->getSystemValue('datadirectory') . '/nextcloud.log';
    $events = [];
    foreach (array_slice(is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [], -500) as $line) {
        $event = json_decode($line, true);
        if (!is_array($event)) { continue; }
        $exception = $event['exception'] ?? [];
        $ownCli = isset($exception['File']) && basename($exception['File']) === 'concurrency-helper.php';
        foreach ($exception['Trace'] ?? [] as $frame) {
            if (isset($frame['file']) && basename($frame['file']) === 'concurrency-helper.php') { $ownCli = true; }
        }
        if (($event['user'] ?? null) !== $owner && !$ownCli) { continue; }
        $events[] = ['time' => $event['time'] ?? null, 'message' => $event['message'] ?? null,
            'exceptionClass' => $exception['Exception'] ?? null, 'exceptionMessage' => $exception['Message'] ?? null,
            'sourceFile' => isset($exception['File']) ? basename($exception['File']) : null, 'sourceLine' => $exception['Line'] ?? null,
            'trace' => array_map(static fn ($frame) => ['class' => $frame['class'] ?? null,
                'function' => $frame['function'] ?? null, 'sourceFile' => isset($frame['file']) ? basename($frame['file']) : null,
                'sourceLine' => $frame['line'] ?? null], array_slice($exception['Trace'] ?? [], 0, 8))];
    }
    $reply = ['jobs' => array_map(static fn ($row) => JobRepository::dto($row, false), $jobs->forOwner($owner)), 'events' => array_slice($events, -6)];
} elseif ($action === 'fixture') {
    $payload = tempnam(sys_get_temp_dir(), 'archive-tools-concurrency-data-');
    $archive = tempnam(sys_get_temp_dir(), 'archive-tools-concurrency-zip-');
    try {
        $handle = fopen($payload, 'wb');
        if ($handle === false) { throw new RuntimeException('Cannot create the bounded fixture.'); }
        try {
            $chunk = str_repeat("Archive Tools concurrency\0\xff\n", 40000);
            $chunk = substr($chunk, 0, 1024 * 1024);
            if (strlen($chunk) !== 1024 * 1024) { throw new RuntimeException('The fixture chunk has an invalid size.'); }
            for ($part = 0; $part < 32; ++$part) {
                if (fwrite($handle, $chunk) !== strlen($chunk)) { throw new RuntimeException('Cannot write the bounded fixture.'); }
            }
        } finally { fclose($handle); }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true
            || !$zip->addFile($payload, 'large.bin')
            || !$zip->setEncryptionName('large.bin', ZipArchive::EM_AES_256, $password)
            || !$zip->close()) { throw new RuntimeException('Cannot create the concurrency ZIP fixture.'); }
        $reply = ['zipBase64' => base64_encode(file_get_contents($archive)), 'bytes' => filesize($payload), 'sha256' => hash_file('sha256', $payload)];
    } finally {
        if (is_file($payload)) { unlink($payload); }
        if (is_file($archive)) { unlink($archive); }
    }
} else {
    $id = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) { throw new RuntimeException('A test job id is required.'); }
    $row = $jobs->find($id);
    if ($row === null || $row['owner_id'] !== $owner) { throw new RuntimeException('The owned test job does not exist.'); }
    $native = [];
    foreach ($list->getJobsIterator(ExtractJob::class, null, 0) as $job) {
        // Core buildJob() can reuse a singleton service. The next iterator
        // item mutates its id/argument, so retain an independent snapshot.
        if ((int)($job->getArgument()['jobId'] ?? 0) === $id) { $native[] = clone $job; }
    }
    if ($action === 'run') {
        if ($row['state'] !== 'queued' || count($native) !== 1) { throw new RuntimeException('One queued native test job is required.'); }
        if ((int)($native[0]->getArgument()['jobId'] ?? 0) !== $id) { throw new RuntimeException('The native worker argument is not the requested owned job.'); }
        \OC\Files\Filesystem::addStorageWrapper('archive-tools-concurrency-test', static function ($mountPoint, $storage) use ($owner) {
            return $storage->getId() === 'home::' . $owner ? new ConcurrencySlowStorage(['storage' => $storage]) : $storage;
        });
        concurrencyMarker(['phase' => 'executing', 'nativeJobId' => $native[0]->getId()]);
        // The real persisted native QueuedJob removes its queue record and
        // executes its normal claim, user scope, locks and extraction service.
        $native[0]->start($list);
        $reply = ['status' => $jobs->find($id)['state']];
        concurrencyMarker(['phase' => 'finished', 'jobStatus' => $reply['status']]);
    } elseif ($action === 'state') {
        $reply = ['job' => JobRepository::dto($row), 'nativeQueueCount' => count($native),
            'secretPresent' => $row['secret'] !== null,
            'secretEncrypted' => $row['secret'] !== null && $row['secret'] !== $password
                && \OC::$server->get(\OCP\Security\ICrypto::class)->decrypt($row['secret']) === $password,
            'secretCleared' => $row['secret'] === null && $row['active_key'] === null];
    } elseif ($action === 'kill') {
        $pid = filter_var($argv[4] ?? null, FILTER_VALIDATE_INT);
        if ($pid === false || $pid < 2 || $row['state'] !== 'running') { throw new RuntimeException('A running owned worker is required.'); }
        if (!concurrencyMatchesPid($pid, $owner, $id, $argv[5] ?? '')) {
            throw new RuntimeException('The PID is not the requested owned test worker.');
        }
        if (!posix_kill($pid, 9)) { throw new RuntimeException('The owned test worker could not be killed.'); }
        $reply = ['killed' => true];
    } elseif ($action === 'expire-own-locks' || $action === 'age-crashed') {
        $pid = filter_var($argv[4] ?? null, FILTER_VALIDATE_INT);
        if ($pid === false || $pid < 2 || posix_kill($pid, 0) || $row['state'] !== 'running') {
            throw new RuntimeException('The owned test worker must have exited before recovery.');
        }
        $markerPath = concurrencyWorkerPath($owner, $id, $argv[5] ?? '');
        $deadMarker = is_file($markerPath) ? json_decode(file_get_contents($markerPath), true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($deadMarker) || ($deadMarker['owner'] ?? null) !== $owner
            || (int)($deadMarker['jobId'] ?? 0) !== $id || (int)($deadMarker['pid'] ?? 0) !== $pid
            || ($deadMarker['phase'] ?? null) !== 'executing') {
            throw new RuntimeException('The dead PID is not bound to this owned interrupted worker.');
        }
        if ($action === 'age-crashed') {
            $jobs->update($id, ['updated_at' => time() - 901]);
            $reply = ['aged' => true];
        } else {
            \OC::$server->get(\OCP\IUserSession::class)->setUser($user);
            \OC_Util::setupFS($owner);
            $root = \OC::$server->get(IRootFolder::class)->getUserFolder($owner);
            $targets = $root->getById((int)$row['target_id']);
            $target = array_shift($targets);
            if (!$target instanceof Folder || $target->getOwner()?->getUID() !== $owner
                || $root->getRelativePath($target->getPath()) !== '/Concurrency/Crash/Output'
                || $row['folder_name'] !== 'Output' || $target->getParent()->getId() !== (int)$row['destination_id']) {
                throw new RuntimeException('The crashed output is outside the owned fixture.');
            }
            $collector = new ConcurrencyLockKeys();
            $storage = $target->getStorage();
            $path = $target->getInternalPath();
            $storage->acquireLock($path . '/large.bin', ILockingProvider::LOCK_SHARED, $collector);
            while ($path !== '' && $path !== '.') {
                $storage->acquireLock($path, ILockingProvider::LOCK_SHARED, $collector);
                $path = dirname($path);
            }
            $sources = $root->getById((int)$row['source_id']);
            $source = array_shift($sources);
            if (!$source instanceof \OCP\Files\File) { throw new RuntimeException('The owned source fixture is unavailable.'); }
            $fixturePrefix = preg_replace('/_(alice|bob)$/', '', $owner);
            $fixtureOwners = [$fixturePrefix . '_alice', $fixturePrefix . '_bob'];
            if (!in_array($source->getOwner()?->getUID(), $fixtureOwners, true)
                || $root->getRelativePath($source->getPath()) !== '/Source/concurrency.zip') {
                throw new RuntimeException('The source is outside the owned concurrency fixture.');
            }
            $db = \OC::$server->get(\OCP\IDBConnection::class);
            $qb = $db->getQueryBuilder();
            $other = $qb->select('id')->from('archive_tools_jobs')
                ->where($qb->expr()->in('owner_id', $qb->createNamedParameter($fixtureOwners, IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('running')))
                ->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($id)))->setMaxResults(1)
                ->executeQuery()->fetchOne();
            if ($other !== false) { throw new RuntimeException('Another owned worker is still active.'); }
            $source->getStorage()->acquireLock($source->getInternalPath(), ILockingProvider::LOCK_SHARED, $collector);
            // Model the elapsed core file-lock TTL without an hour-long sleep.
            // Only exact canonical keys of our dead worker's own fixture are
            // expired; no global lock cleanup or active worker is involved.
            $reply = concurrencyExpireLocks(array_keys($collector->keys), $owner, $id, $pid) + ['deadWorkerVerified' => true];
        }
    } else { throw new RuntimeException('Unknown concurrency helper action.'); }
}
$output = json_encode($reply, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
echo str_replace([$password, getenv('NEXTCLOUD_ADMIN_PASSWORD')], '[redacted]', $output) . "\n";
} catch (Throwable $error) {
    $safe = concurrencySafeError($error);
    concurrencyMarker(['phase' => 'failed'] + $safe);
    fwrite(STDERR, json_encode($safe, JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
