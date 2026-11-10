<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\BackgroundJob;

use OCA\ArchiveTools\Service\ExtractionService;
use OCA\ArchiveTools\Service\JobRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\ISetupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

final class ExtractJob extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private ExtractionService $extraction,
        private JobRepository $jobs,
        private IUserManager $users,
        private IUserSession $session,
        private ISetupManager $filesystems,
        private LoggerInterface $logger,
    ) { parent::__construct($time); }

    protected function run($argument): void {
        $id = (int)($argument['jobId'] ?? 0);
        $row = $this->jobs->find($id);
        if ($row === null || $row['state'] !== 'queued') { return; }
        $user = $this->users->get($row['owner_id']);
        if ($user === null) { $this->extraction->failUnavailableUser($id); return; }
        $previous = $this->session->getUser();
        try {
            $this->filesystems->tearDown();
            $this->session->setUser($user);
            $this->filesystems->setupForUser($user);
            $this->extraction->run($id);
        } catch (\Throwable $error) {
            $current = $this->jobs->find($id);
            if ($current !== null && in_array($current['state'], ['queued', 'running'], true)) {
                $this->jobs->update($id, ['state' => 'failed', 'secret' => null, 'active_key' => null,
                    'message' => $current['state'] === 'queued'
                        ? 'The extraction user context could not be initialized.'
                        : 'Extraction was interrupted. Any partial output was retained for review.']);
            }
            $this->logger->warning('An archive extraction user scope failed.', ['app' => 'archive_tools', 'jobId' => $id, 'exceptionClass' => $error::class]);
        } finally {
            $this->filesystems->tearDown();
            $this->session->setUser($previous);
            if ($previous !== null) { $this->filesystems->setupForUser($previous); }
        }
    }
}
