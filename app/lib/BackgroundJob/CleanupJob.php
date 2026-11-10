<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\BackgroundJob;

use OCA\ArchiveTools\Service\IdleCleanupService;
use OCA\ArchiveTools\Service\ExtractionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

final class CleanupJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private IdleCleanupService $cleanup,
        private ExtractionService $extraction,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(300);
        // Idle expiry and interrupted-job recovery must also run outside maintenance hours.
        $this->setTimeSensitivity(self::TIME_SENSITIVE);
    }

    protected function run(mixed $argument): void {
        try {
            $this->extraction->reconcile();
        } catch (\Throwable $error) {
            $this->logger->warning('Archive task recovery could not be completed.', [
                'app' => 'archive_tools', 'exceptionClass' => $error::class,
            ]);
        }
        $this->cleanup->cleanup();
    }
}
