<?php
declare(strict_types=1);

final class InterruptedArchiveWrite extends php_user_filter {
    public function filter($in, $out, &$consumed, bool $closing): int {
        while ($bucket = stream_bucket_make_writeable($in)) {
            if ($this->params->partialBytes === 0) {
                $path = $this->params->physicalPath;
                clearstatcache(true, $path);
                $size = filesize($path);
                if ($size > 0 && $size < $this->params->expectedBytes) {
                    $this->params->partialBytes = $size;
                    $this->params->prefixHash = hash_file('sha256', $path);
                    throw new RuntimeException('Synthetic failure writing to shared storage.');
                }
            }
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}

require '/var/www/html/lib/base.php';
\OC_App::loadApps();
$action = $argv[1] ?? '';
$owner = $argv[2] ?? '';
if (!preg_match('/^at_\d{14}_[a-f0-9]{6}_(alice|bob|outsider)$/', $owner)) { throw new RuntimeException('Only generated test users are allowed.'); }
$user = \OC::$server->get(\OCP\IUserManager::class)->get($owner);
if ($user === null) { throw new RuntimeException('The test user does not exist.'); }
$password = hash('sha256', getenv('NEXTCLOUD_ADMIN_PASSWORD') . '::' . $owner);
$jobs = \OC::$server->get(\OCA\ArchiveTools\Service\JobRepository::class);
$reply = [];
if ($action === 'fixture' || $action === 'long-fixture') {
    $file = tempnam(sys_get_temp_dir(), 'archive-tools-queue-');
    try {
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Cannot create test archive.'); }
        if ($action === 'fixture') {
            $zip->addFromString('public.txt', 'public test member');
            $zip->addFromString('private/secret.bin', "authenticated content\0\xff");
            if (!$zip->setEncryptionName('private/secret.bin', ZipArchive::EM_AES_256, $password)) { throw new RuntimeException('AES fixture encryption is unavailable.'); }
        } else {
            for ($i = 0; $i < 1000; $i++) { $zip->addFromString(sprintf('many/file-%04d.txt', $i), str_repeat('cancel fixture ', 100)); }
        }
        if (!$zip->close()) { throw new RuntimeException('Cannot finish test archive.'); }
        $reply = ['zipBase64' => base64_encode(file_get_contents($file))];
    } finally { if (is_file($file)) { unlink($file); } }
} elseif ($action === 'credentials') {
    $id = (int)($argv[3] ?? 0);
    $row = $jobs->find($id);
    if ($row === null || $row['owner_id'] !== $owner) { throw new RuntimeException('Test job not found.'); }
    $coreArguments = [];
    $list = \OC::$server->get(\OCP\BackgroundJob\IJobList::class);
    foreach ($list->getJobsIterator(\OCA\ArchiveTools\BackgroundJob\ExtractJob::class, null, 0) as $job) {
        if ((int)($job->getArgument()['jobId'] ?? 0) === $id) { $coreArguments[] = $job->getArgument(); }
    }
    $reply = [
        'plaintextAbsentFromJob' => !str_contains(json_encode($row, JSON_THROW_ON_ERROR), $password),
        'encryptedSecretMatches' => $row['secret'] !== null && $row['secret'] !== $password
            && \OC::$server->get(\OCP\Security\ICrypto::class)->decrypt($row['secret']) === $password,
        'secretCleared' => $row['secret'] === null,
        'coreArgumentsContainOnlyJobId' => count($coreArguments) === 1 && $coreArguments[0] === ['jobId' => $id],
        'plaintextAbsentFromCoreArguments' => !str_contains(json_encode($coreArguments, JSON_THROW_ON_ERROR), $password),
    ];
} elseif ($action === 'reconcile-queue') {
    $id = (int)($argv[3] ?? 0);
    $row = $jobs->find($id);
    if ($row === null || $row['owner_id'] !== $owner || $row['state'] !== 'queued') {
        throw new RuntimeException('Queued test job not found.');
    }
    $class = \OCA\ArchiveTools\BackgroundJob\ExtractJob::class;
    $argument = ['jobId' => $id];
    $list = \OCP\Server::get(\OCP\BackgroundJob\IJobList::class);
    $nativeId = null;
    foreach ($list->getJobsIterator($class, null, 0) as $job) {
        if ($job->getArgument() === $argument) {
            $nativeId = $job->getId();
            break;
        }
    }
    if ($nativeId === null) { throw new RuntimeException('Native test job not found.'); }

    $db = \OCP\Server::get(\OCP\IDBConnection::class);
    $extraction = \OCP\Server::get(\OCA\ArchiveTools\Service\ExtractionService::class);
    $db->beginTransaction();
    try {
        // Model a selected fixture that has not yet started. Roll back all
        // changes so the real queue can execute its original entry next.
        $lastChecked = time() - 60;
        $reservedAt = time();
        $qb = $db->getQueryBuilder();
        $updated = $qb->update('jobs')
            ->set('last_checked', $qb->createNamedParameter($lastChecked))
            ->set('reserved_at', $qb->createNamedParameter($reservedAt))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($nativeId)))
            ->executeStatement();
        if ($updated !== 1) { throw new RuntimeException('Cannot model a native job reservation.'); }

        $extraction->reconcile();
        $qb = $db->getQueryBuilder();
        $native = $qb->select('last_checked', 'reserved_at')->from('jobs')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($nativeId)))
            ->executeQuery()->fetchAssociative();
        $preserved = $native !== false
            && (int)$native['last_checked'] === $lastChecked
            && (int)$native['reserved_at'] === $reservedAt;

        $list->remove($class, $argument);
        $missing = !$list->has($class, $argument);
        $extraction->reconcile();
        $reply = [
            'existingQueueMetadataPreserved' => $preserved,
            'missingNativeJobRestored' => $missing && $list->has($class, $argument),
        ];
    } finally {
        $db->rollBack();
    }
} elseif ($action === 'notification') {
    $id = (int)($argv[3] ?? 0);
    $row = $jobs->find($id);
    if ($row === null || $row['owner_id'] !== $owner) { throw new RuntimeException('Test job not found.'); }
    $db = \OC::$server->get(\OCP\IDBConnection::class);
    $qb = $db->getQueryBuilder();
    $notificationRow = $qb->select('subject', 'subject_parameters')->from('notifications')
        ->where($qb->expr()->eq('app', $qb->createNamedParameter('archive_tools')))
        ->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($owner)))
        ->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$id)))->executeQuery()->fetchAssociative();
    $reply = ['persisted' => $notificationRow !== false, 'subject' => null, 'localizedSubject' => null];
    if ($notificationRow !== false) {
        $notification = \OC::$server->get(\OCP\Notification\IManager::class)->createNotification()
            ->setApp('archive_tools')->setUser($owner)->setObject('extraction', (string)$id)
            ->setSubject($notificationRow['subject'], json_decode($notificationRow['subject_parameters'], true, 512, JSON_THROW_ON_ERROR));
        $prepared = \OC::$server->get(\OCA\ArchiveTools\Notification\Notifier::class)->prepare($notification, 'ru');
        $reply['subject'] = $notificationRow['subject'];
        $reply['localizedSubject'] = $prepared->getParsedSubject();
    }
} elseif ($action === 'fail-shared-write') {
    $id = (int)($argv[3] ?? 0);
    $row = $jobs->find($id);
    $member = 'nested/payload.bin';
    $plan = json_decode((string)($row['plan_json'] ?? ''), true);
    $expectedBytes = (int)($plan[$member]['size'] ?? 0);
    if ($row === null || $row['owner_id'] !== $owner || $row['state'] !== 'queued'
        || $row['folder_name'] !== 'Write failure output' || !str_ends_with($owner, '_bob')
        || $expectedBytes < 65536) {
        throw new RuntimeException('Expected a queued write failure fixture.');
    }
    $setup = \OCP\Server::get(\OCP\Files\ISetupManager::class);
    $session = \OCP\Server::get(\OCP\IUserSession::class);
    $previous = $session->getUser();
    $fixtureOwner = substr($owner, 0, -3) . 'alice';
    $dataDirectory = \OCP\Server::get(\OCP\IConfig::class)->getSystemValue('datadirectory');
    $physicalOutput = $dataDirectory . '/' . $fixtureOwner . '/files/Create-only destination/Write failure output';
    $fault = (object)['physicalPath' => $physicalOutput . '/' . $member, 'expectedBytes' => $expectedBytes,
        'partialBytes' => 0, 'prefixHash' => null];
    try {
        $setup->tearDown();
        $session->setUser($user);
        $setup->setupForUser($user);
        $root = \OCP\Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($owner);
        $destination = $root->get('Create-only destination');
        if (!$destination instanceof \OCP\Files\Folder || $destination->getId() !== (int)$row['destination_id']) {
            throw new RuntimeException('The job does not target the test share.');
        }
        $mount = $destination->getMountPoint();
        $storage = $mount->getStorage();
        $target = $mount->getInternalPath($destination->getPath() . '/' . $row['folder_name'] . '/' . $member);
        if (!stream_filter_register('archive-tools.test.write-failure', InterruptedArchiveWrite::class)) {
            throw new RuntimeException('Could not register the test stream filter.');
        }
        \OCP\Server::get(\OCP\BackgroundJob\IJobList::class)->remove(
            \OCA\ArchiveTools\BackgroundJob\ExtractJob::class, ['jobId' => $id],
        );
        $mount->wrapStorage(static fn () => new class (['storage' => $storage], $target, $fault) extends \OC\Files\Storage\Wrapper\Wrapper {
            public function __construct(array $parameters, private string $target = '', private ?object $fault = null) {
                parent::__construct($parameters);
            }

            public function fopen(string $path, string $mode) {
                $stream = parent::fopen($path, $mode);
                if ($path === $this->target && $mode === 'w' && is_resource($stream)
                    && stream_filter_append($stream, 'archive-tools.test.write-failure', STREAM_FILTER_WRITE, $this->fault) === false) {
                    fclose($stream);
                    throw new RuntimeException('Could not attach the test stream filter.');
                }
                return $stream;
            }
        });
        try {
            \OCP\Server::get(\OCA\ArchiveTools\Service\ExtractionService::class)->run($id);
        } finally {
            $mount->wrapStorage(static fn () => $storage);
        }
        clearstatcache(true, $physicalOutput);
        $reply = ['partialBytes' => $fault->partialBytes, 'expectedBytes' => $expectedBytes,
            'prefixHash' => $fault->prefixHash, 'outputExists' => is_dir($physicalOutput)];
    } finally {
        $setup->tearDown();
        $session->setUser($previous);
        if ($previous !== null) { $setup->setupForUser($previous); }
    }
} elseif ($action === 'mark-interrupted') {
    $id = (int)($argv[3] ?? 0);
    $targetId = (int)($argv[4] ?? 0);
    $row = $jobs->find($id);
    if ($row === null || $row['owner_id'] !== $owner || $row['state'] !== 'queued') { throw new RuntimeException('Queued test job not found.'); }
    \OC::$server->get(\OCP\IUserSession::class)->setUser($user);
    \OC_Util::setupFS($owner);
    $candidates = \OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder($owner)->getById($targetId);
    $target = array_shift($candidates);
    if (!$target instanceof \OCP\Files\Folder || $target->getOwner()?->getUID() !== $owner) { throw new RuntimeException('Owned test output folder not found.'); }
    $jobs->update($id, ['state' => 'running', 'target_id' => $targetId, 'updated_at' => time() - 1000]);
    \OC::$server->get(\OCA\ArchiveTools\Service\ExtractionService::class)->reconcile();
    $reply = ['job' => \OCA\ArchiveTools\Service\JobRepository::dto($jobs->find($id)), 'secretCleared' => $jobs->find($id)['secret'] === null];
} elseif ($action === 'logs') {
    $file = \OC::$server->get(\OCP\IConfig::class)->getSystemValue('datadirectory') . '/nextcloud.log';
    $reply = ['plaintextAbsentFromLog' => !is_file($file) || !str_contains(file_get_contents($file), $password)];
} else { throw new RuntimeException('Unknown test helper action.'); }
echo json_encode($reply, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
