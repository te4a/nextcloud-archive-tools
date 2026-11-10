<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Repair;

use OCA\ArchiveTools\Service\JobRepository;
use OCA\ArchiveTools\Service\LifecycleService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Install/upgrade only. Ordinary disable must preserve settings and pins. */
final class RepairApplicationState implements IRepairStep {
    public function __construct(private LifecycleService $lifecycle, private JobRepository $jobs) {}

    public function getName(): string { return 'Initialize Archive Tools lifecycle state and clear terminal task credentials'; }

    public function run(IOutput $output): void {
        $this->lifecycle->initialize();
        $result = $this->jobs->cleanupHistory(0);
        $output->info('Archive Tools lifecycle state is ready. Terminal task credentials cleared: ' . $result['secretsCleared'] . '.');
    }
}
