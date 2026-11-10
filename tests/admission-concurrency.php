<?php
declare(strict_types=1);

// Two real PHP processes contend for the final persisted slot. No HTTP route
// loads this script; every committed fixture belongs to one generated owner.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
\OC_App::loadApps();
require __DIR__ . '/admission-fixtures.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'FAIL admission concurrency: ' . $error::class . ': ' . substr($error->getMessage(), 0, 700) . "\n");
    exit(1);
});

use OCA\ArchiveTools\Service\AdmissionService;
use OCA\ArchiveTools\Service\LifecycleService;
use OCA\ArchiveTools\Service\ResourceLimitException;

$db = \OCP\Server::get(\OCP\IDBConnection::class);
$lifecycle = \OCP\Server::get(LifecycleService::class);

function admissionGate(string $token): string {
    if (!preg_match('/^[a-f0-9]{24}$/D', $token)) { throw new RuntimeException('Invalid owned admission barrier.'); }
    return sys_get_temp_dir() . '/archive-tools-admission-' . $token . '.gate';
}

if (($argv[1] ?? '') === '--worker') {
    $token = $argv[2] ?? '';
    $owner = 'at_admission_' . $token . '_alice';
    admissionFixtureOwner($owner);
    $kind = $argv[3] ?? '';
    if (!in_array($kind, ['connection', 'job'], true)) { throw new RuntimeException('Unknown owned admission worker.'); }
    $limits = json_decode($argv[4] ?? '', true, 16, JSON_THROW_ON_ERROR);
    $service = admissionFixtureService($db, $limits);
    // Both preflights deliberately see room; only the serialized final check
    // can prevent oversubscription once their common barrier is released.
    if ($kind === 'connection') { $service->assertConnection($owner, '{}'); }
    else { $service->assertJob($owner, '{}', null); }
    echo "READY\n"; flush();
    $deadline = microtime(true) + 15;
    $gate = admissionGate($token);
    while (!is_file($gate)) {
        if (microtime(true) > $deadline) { throw new RuntimeException('The owned admission barrier timed out.'); }
        usleep(10000);
        clearstatcache(true, $gate);
    }
    try {
        $lifecycle->write(function () use ($service, $db, $owner, $kind): void {
            if ($kind === 'connection') { $service->assertConnection($owner, '{}'); }
            else { $service->assertJob($owner, '{}', null); }
            usleep(100000); // Keep the winning transaction open for the contender.
            if ($kind === 'connection') { admissionFixtureMount($db, $owner); }
            else { admissionFixtureJob($db, $owner, 'queued', '{}'); }
        });
        echo "ADMITTED\n";
    } catch (ResourceLimitException) { echo "REJECTED\n"; }
    exit(0);
}

$checks = 0;
function concurrentAdmissionCheck(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
    echo 'PASS ' . $message . "\n";
}

function admissionConcurrentCount(\OCP\IDBConnection $db, string $table, ?string $owner = null): int {
    $qb = $db->getQueryBuilder();
    $qb->select($qb->func()->count('*'))->from($table);
    if ($owner !== null) { $qb->where($qb->expr()->eq($table === 'archive_tools_mounts' ? 'created_by' : 'owner_id', $qb->createNamedParameter($owner))); }
    return (int)$qb->executeQuery()->fetchOne();
}

function admissionRunContenders(string $token, string $kind, array $limits): array {
    $workers = [];
    $gate = admissionGate($token);
    try {
        for ($index = 0; $index < 2; ++$index) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, '--worker', $token, $kind, json_encode($limits, JSON_THROW_ON_ERROR)],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) { throw new RuntimeException('An owned admission contender could not start.'); }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            $workers[] = ['process' => $process, 'out' => $pipes[1], 'err' => $pipes[2], 'stdout' => '', 'stderr' => '', 'exit' => null];
        }
        $deadline = microtime(true) + 15;
        while (true) {
            $ready = true;
            foreach ($workers as &$worker) {
                $worker['stdout'] .= stream_get_contents($worker['out'], 4096);
                $worker['stderr'] .= stream_get_contents($worker['err'], 4096);
                $ready = $ready && str_contains($worker['stdout'], "READY\n");
            }
            unset($worker);
            if ($ready) { break; }
            if (microtime(true) > $deadline) { throw new RuntimeException('Two owned admission contenders did not reach their barrier.'); }
            usleep(10000);
        }
        concurrentAdmissionCheck(true, 'both contenders pass preflight before either insertion');
        $handle = fopen($gate, 'xb');
        if (!is_resource($handle)) { throw new RuntimeException('The owned admission gate could not be created.'); }
        fclose($handle); chmod($gate, 0600);
        $deadline = microtime(true) + 20;
        while (true) {
            $done = true;
            foreach ($workers as &$worker) {
                $worker['stdout'] .= stream_get_contents($worker['out'], 4096);
                $worker['stderr'] .= stream_get_contents($worker['err'], 4096);
                if (strlen($worker['stdout']) + strlen($worker['stderr']) > 16384) { throw new RuntimeException('An owned admission contender exceeded its diagnostic budget.'); }
                $status = proc_get_status($worker['process']);
                if ($status['running']) { $done = false; }
                elseif ($status['exitcode'] >= 0) { $worker['exit'] = $status['exitcode']; }
            }
            unset($worker);
            if ($done) { break; }
            if (microtime(true) > $deadline) { throw new RuntimeException('The owned admission contenders did not finish within their deadline.'); }
            usleep(10000);
        }
        $results = [];
        foreach ($workers as $worker) {
            if ($worker['exit'] !== 0) { throw new RuntimeException('An owned admission contender failed.'); }
            $results[] = str_contains($worker['stdout'], "ADMITTED\n") ? 'admitted' : (str_contains($worker['stdout'], "REJECTED\n") ? 'rejected' : 'unknown');
        }
        sort($results);
        return $results;
    } finally {
        foreach ($workers as $worker) {
            if (is_resource($worker['process'])) {
                if (proc_get_status($worker['process'])['running']) { proc_terminate($worker['process'], 9); }
                fclose($worker['out']); fclose($worker['err']); proc_close($worker['process']);
            }
        }
        if (is_file($gate)) { unlink($gate); }
    }
}

foreach (['owner connection', 'global connection', 'owner active job', 'owner history', 'global metadata'] as $case) {
    $token = bin2hex(random_bytes(12));
    $owner = 'at_admission_' . $token . '_alice';
    $limits = array_fill_keys(array_keys(AdmissionService::DEFAULT_LIMITS), 1073741824);
    $kind = str_contains($case, 'connection') ? 'connection' : 'job';
    try {
        if ($case === 'owner connection') { $limits['connections_per_owner'] = 1; }
        elseif ($case === 'global connection') { $limits['connections_global'] = admissionConcurrentCount($db, 'archive_tools_mounts') + 1; }
        elseif ($case === 'owner active job') { $limits['active_jobs_per_owner'] = 1; }
        elseif ($case === 'owner history') { $limits['history_per_owner'] = 1; }
        else {
            $service = admissionFixtureService($db, $limits);
            $method = (new ReflectionClass(AdmissionService::class))->getMethod('usage');
            $limits['metadata_bytes_global'] = $method->invoke($service, false, null)['metadata_bytes']
                + $method->invoke($service, true, null)['metadata_bytes'] + 2;
        }
        $results = admissionRunContenders($token, $kind, $limits);
        concurrentAdmissionCheck($results === ['admitted', 'rejected'], $case . ': exactly one contender obtains the final slot');
        concurrentAdmissionCheck(admissionConcurrentCount($db, $kind === 'connection' ? 'archive_tools_mounts' : 'archive_tools_jobs', $owner) === 1,
            $case . ': persisted count never exceeds the configured ceiling');
    } finally { $lifecycle->write(fn () => admissionFixtureRemove($db, $owner)); }
}
echo 'All ' . $checks . " admission concurrency tests passed.\n";
