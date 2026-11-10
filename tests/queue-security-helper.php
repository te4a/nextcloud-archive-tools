<?php
declare(strict_types=1);

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
