<?php
declare(strict_types=1);

require '/var/www/html/lib/base.php';
\OC_App::loadApps();
$id = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id < 1) {
    exit(2);
}
$list = \OC::$server->get(\OCP\BackgroundJob\IJobList::class);
foreach ($list->getJobsIterator(\OCA\ArchiveTools\BackgroundJob\ExtractJob::class, null, 0) as $job) {
    if ((int)($job->getArgument()['jobId'] ?? 0) === $id) {
        echo $job->getId();
        exit(0);
    }
}
echo '0';
