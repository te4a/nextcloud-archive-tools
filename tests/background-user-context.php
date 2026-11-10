<?php
declare(strict_types=1);

// Run only through the guarded local/CI test transport. Every mutation belongs
// to the generated users below; their jobs, queue entries and files are removed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
\OC_App::loadApps();

use OCA\ArchiveTools\BackgroundJob\ExtractJob;
use OCA\ArchiveTools\Archive\OperationBudget;
use OCA\ArchiveTools\Service\ExtractionService;
use OCA\ArchiveTools\Service\JobRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IRootFolder;
use OCP\Files\ISetupManager;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Psr\Log\LoggerInterface;

final class ContextSetupProbe implements ISetupManager {
    public array $calls = [];
    public ?string $failOwner = null;

    public function __construct(private ISetupManager $inner, private IUserSession $session) {}

    public function setupForUser(IUser $user): void {
        $this->calls[] = ['setup', $user->getUID(), $this->session->getUser()?->getUID()];
        if ($this->failOwner === $user->getUID()) { throw new RuntimeException('Injected own-fixture setup failure.'); }
        $this->inner->setupForUser($user);
    }

    public function tearDown(): void {
        $this->calls[] = ['teardown', $this->session->getUser()?->getUID()];
        $this->inner->tearDown();
    }

    public function setupForPath(string $path, bool $includeChildren = false): void {
        $this->inner->setupForPath($path, $includeChildren);
    }

    public function isSetupComplete(IUser $user): bool { return $this->inner->isSetupComplete($user); }
}

$checks = 0;
function contextCheck(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
    echo 'PASS ' . $message . PHP_EOL;
}

function contextZip(string $content): string {
    $path = tempnam(sys_get_temp_dir(), 'archive-tools-context-');
    if ($path === false) { throw new RuntimeException('Cannot reserve the own-fixture ZIP.'); }
    try {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true
            || !$zip->addFromString('private/owner.txt', $content) || !$zip->close()) {
            throw new RuntimeException('Cannot create the own-fixture ZIP.');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) { throw new RuntimeException('Cannot read the own-fixture ZIP.'); }
        return $bytes;
    } finally { if (is_file($path)) { unlink($path); } }
}

$users = Server::get(IUserManager::class);
$session = Server::get(IUserSession::class);
$filesystems = Server::get(ISetupManager::class);
$root = Server::get(IRootFolder::class);
$jobs = Server::get(JobRepository::class);
$extraction = Server::get(ExtractionService::class);
$queue = Server::get(IJobList::class);
$db = Server::get(IDBConnection::class);
$priorUser = $session->getUser();
$namespace = 'at_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(3));
$owned = [];
$jobIds = [];
$failure = null;
$cleanupFailure = null;
$phase = 'resolve-worker';

try {
    contextCheck(Server::get(ExtractJob::class) instanceof ExtractJob, 'worker resolves its public filesystem setup dependency');
    $phase = 'create-own-users';
    foreach (['alice', 'bob', 'observer'] as $role) {
        $uid = $namespace . '_' . $role;
        if ($users->get($uid) !== null) { throw new RuntimeException('Own fixture user already exists.'); }
        $user = $users->createUser($uid, bin2hex(random_bytes(24)));
        if (!$user instanceof IUser) { throw new RuntimeException('Cannot create own fixture user.'); }
        $owned[$role] = $user;
    }

    $sourceIds = [];
    $expected = [];
    foreach (['alice', 'bob'] as $role) {
        $phase = 'schedule-' . $role;
        $owner = $owned[$role];
        $filesystems->tearDown();
        $session->setUser($owner);
        $filesystems->setupForUser($owner);
        $home = $root->getUserFolder($owner->getUID());
        $expected[$role] = 'own context ' . $role . "\n";
        $source = $home->newFile('context.zip', contextZip($expected[$role]));
        $sourceIds[$role] = $source->getId();
        $job = $extraction->schedule($owner->getUID(), $source->getId(), $home->getId(), 'Context output', [], null);
        $jobIds[$role] = (int)$job['id'];
        contextCheck($job['status'] === 'queued', $role . ': real extraction task is queued');
    }

    $observer = $owned['observer'];
    $filesystems->tearDown();
    $session->setUser($observer);
    $filesystems->setupForUser($observer);
    $root->getUserFolder($observer->getUID())->newFile('keep.txt', 'previous context remains intact');

    $probe = new ContextSetupProbe($filesystems, $session);
    $worker = new ExtractJob(Server::get(ITimeFactory::class), $extraction, $jobs, $users, $session, $probe,
        Server::get(LoggerInterface::class));
    $run = new ReflectionMethod(ExtractJob::class, 'run');

    // Reuse this exact worker/service graph across both owners, rather than
    // starting a fresh PHP process that would hide leaked user state.
    foreach (['alice', 'bob'] as $role) {
        $phase = 'execute-' . $role;
        $owner = $owned[$role];
        $probe->calls = [];
        $run->invoke($worker, ['jobId' => $jobIds[$role]]);
        contextCheck($probe->calls === [
            ['teardown', $observer->getUID()],
            ['setup', $owner->getUID(), $owner->getUID()],
            ['teardown', $owner->getUID()],
            ['setup', $observer->getUID(), $observer->getUID()],
        ], $role . ': setup and teardown use the correct active identities');
        contextCheck($session->getUser()?->getUID() === $observer->getUID(), $role . ': previous active user is restored');
        contextCheck($filesystems->isSetupComplete($observer), $role . ': previous filesystem is restored');
        contextCheck($jobs->find($jobIds[$role])['state'] === 'succeeded', $role . ': same-worker extraction succeeds');
        $home = $root->getUserFolder($owner->getUID());
        contextCheck($home->get('Context output/private/owner.txt')->getContent() === $expected[$role],
            $role . ': only that owner receives its exact source bytes');
        contextCheck($root->getUserFolder($observer->getUID())->get('keep.txt')->getContent() === 'previous context remains intact',
            $role . ': previous user files remain intact');
        $filesystems->tearDown();
        $session->setUser($observer);
        $filesystems->setupForUser($observer);
    }
    contextCheck(!$root->getUserFolder($observer->getUID())->nodeExists('Context output'), 'no extraction is published into the previous user home');

    $phase = 'deadline-rollback';
    $owner = $owned['alice'];
    $filesystems->tearDown();
    $session->setUser($owner);
    $filesystems->setupForUser($owner);
    $home = $root->getUserFolder($owner->getUID());
    $job = $extraction->schedule($owner->getUID(), $sourceIds['alice'], $home->getId(), 'Deadline output', [], null);
    $jobIds['deadline'] = (int)$job['id'];
    // Expire only after the real output folder is reserved and its identity is
    // persisted. No sleeping, global clock override, or database ETag change.
    $deadline = new OperationBudget(1, static fn (): float => $jobs->find($jobIds['deadline'])['target_id'] === null ? 0.0 : 2.0);
    $extraction->run($jobIds['deadline'], $deadline);
    $timedOut = $jobs->find($jobIds['deadline']);
    contextCheck($timedOut['state'] === 'failed' && $timedOut['message'] === 'The archive operation exceeded its time limit.',
        'operation deadline fails the task with a fixed safe message');
    contextCheck(!$home->nodeExists('Deadline output'), 'operation deadline rolls back its already reserved output');
    contextCheck($home->newFile('after-deadline.txt', 'locks released')->getContent() === 'locks released',
        'operation deadline releases destination reservations');

    $owner = $owned['bob'];
    $filesystems->tearDown();
    $session->setUser($owner);
    $filesystems->setupForUser($owner);
    $job = $extraction->schedule($owner->getUID(), $sourceIds['bob'], $root->getUserFolder($owner->getUID())->getId(),
        'After deadline', [], null);
    $jobIds['afterDeadline'] = (int)$job['id'];
    $filesystems->tearDown();
    $session->setUser($observer);
    $filesystems->setupForUser($observer);
    $run->invoke($worker, ['jobId' => $jobIds['afterDeadline']]);
    contextCheck($jobs->find($jobIds['afterDeadline'])['state'] === 'succeeded', 'same worker gives the next job a fresh operation budget');
    contextCheck($session->getUser()?->getUID() === $observer->getUID() && $filesystems->isSetupComplete($observer),
        'next job after timeout restores the previous context');
    contextCheck($root->getUserFolder($owner->getUID())->get('After deadline/private/owner.txt')->getContent() === $expected['bob'],
        'next job after timeout writes exact owner-specific bytes');

    $owner = $owned['alice'];
    $phase = 'setup-failure';
    $filesystems->tearDown();
    $session->setUser($owner);
    $filesystems->setupForUser($owner);
    $job = $extraction->schedule($owner->getUID(), $sourceIds['alice'], $root->getUserFolder($owner->getUID())->getId(),
        'Failed context output', [], null);
    $jobIds['setupFailure'] = (int)$job['id'];
    $filesystems->tearDown();
    $session->setUser($observer);
    $filesystems->setupForUser($observer);
    $probe->calls = [];
    $probe->failOwner = $owner->getUID();
    $run->invoke($worker, ['jobId' => $jobIds['setupFailure']]);
    $failed = $jobs->find($jobIds['setupFailure']);
    contextCheck($failed['state'] === 'failed' && $failed['active_key'] === null && $failed['secret'] === null,
        'setup exception terminates only its task and clears transient credentials');
    contextCheck($session->getUser()?->getUID() === $observer->getUID() && $filesystems->isSetupComplete($observer),
        'setup exception restores the previous user and filesystem');
    contextCheck($probe->calls === [
        ['teardown', $observer->getUID()],
        ['setup', $owner->getUID(), $owner->getUID()],
        ['teardown', $owner->getUID()],
        ['setup', $observer->getUID(), $observer->getUID()],
    ], 'failed setup still executes the complete context restoration sequence');
    contextCheck(!$root->getUserFolder($owner->getUID())->nodeExists('Failed context output'),
        'setup failure publishes no partial output');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    try {
        foreach ($jobIds as $id) {
            $row = $jobs->find($id);
            if ($row !== null && !in_array($row['owner_id'], array_map(static fn (IUser $u) => $u->getUID(), $owned), true)) {
                throw new RuntimeException('Own-fixture job ownership changed; refusing cleanup.');
            }
            $queue->remove(ExtractJob::class, ['jobId' => $id]);
            if ($row !== null) {
                $qb = $db->getQueryBuilder();
                $qb->delete('archive_tools_jobs')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
                    ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($row['owner_id'])))->executeStatement();
            }
        }
        $filesystems->tearDown();
        $session->setUser(null);
        foreach (array_reverse($owned) as $user) {
            if (!str_starts_with($user->getUID(), $namespace . '_')) { throw new RuntimeException('Unsafe own-fixture user cleanup.'); }
            if (!$user->delete()) { throw new RuntimeException('Own-fixture user cleanup failed.'); }
        }
        $filesystems->tearDown();
        $session->setUser($priorUser);
        if ($priorUser !== null) { $filesystems->setupForUser($priorUser); }
        foreach ($owned as $user) {
            if ($users->get($user->getUID()) !== null) { throw new RuntimeException('Own-fixture user remained after cleanup.'); }
        }
        foreach ($jobIds as $id) {
            if ($jobs->find($id) !== null || $queue->has(ExtractJob::class, ['jobId' => $id])) {
                throw new RuntimeException('Own-fixture queue/task metadata remained after cleanup.');
            }
        }
        contextCheck(true, 'own users and their queue/task metadata are cleaned up');
    } catch (Throwable $error) { $cleanupFailure = $error; }
}

if ($failure !== null || $cleanupFailure !== null) {
    // Exception classes and test-defined messages suffice; never print user
    // credentials, arbitrary core exception messages or server configuration.
    fwrite(STDERR, 'Background context regression failed at phase ' . $phase . PHP_EOL);
    $diagnostic = $failure ?? $cleanupFailure;
    for ($depth = 0; $diagnostic !== null && $depth < 8; ++$depth, $diagnostic = $diagnostic->getPrevious()) {
        fwrite(STDERR, $diagnostic::class . ' ' . basename($diagnostic->getFile()) . ':' . $diagnostic->getLine() . PHP_EOL);
    }
    if ($failure instanceof RuntimeException && $failure->getFile() === __FILE__) { fwrite(STDERR, $failure->getMessage() . PHP_EOL); }
    if ($cleanupFailure !== null) { fwrite(STDERR, 'Own-fixture cleanup requires review: ' . $namespace . PHP_EOL); }
    exit(1);
}
echo 'All ' . $checks . ' background user context tests passed.' . PHP_EOL;
