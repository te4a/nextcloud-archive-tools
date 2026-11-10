<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Repair;

use OCA\ArchiveTools\Service\LifecycleService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Only an explicit core install is authorized to reopen a purged app. */
final class InstallApplicationState implements IRepairStep {
    public function __construct(private LifecycleService $lifecycle) {}

    public function getName(): string { return 'Initialize a fresh Archive Tools installation'; }

    public function run(IOutput $output): void {
        $this->lifecycle->initialize(true);
        $output->info('Archive Tools lifecycle state is ready for the new installation.');
    }
}
