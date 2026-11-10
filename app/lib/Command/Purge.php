<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Command;

use OCA\ArchiveTools\Service\ApplicationCleanupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Counts by default; destructive cleanup always requires an explicit flag. */
final class Purge extends Command {
    public function __construct(private ApplicationCleanupService $cleanup) { parent::__construct(); }

    protected function configure(): void {
        $this->setName('archive_tools:purge')
            ->setDescription('Report Archive Tools metadata; --force disables the app and permanently purges its metadata while preserving user files.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Disable Archive Tools and remove its settings, credentials, tasks, connections and virtual cache metadata.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $format = $input->getOption('output');
        if (!in_array($format, ['text', 'json'], true)) { $output->writeln('<error>Output format must be text or json.</error>'); return Command::INVALID; }
        try {
            $forced = (bool)$input->getOption('force');
            $result = $forced ? $this->cleanup->purge() : $this->cleanup->report();
            if ($format === 'json') { $output->writeln(json_encode(['purged' => $forced, 'result' => $result], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)); }
            elseif ($forced) {
                $output->writeln('Archive Tools metadata was purged. Source archives and extracted user files were preserved.');
                if (isset($result['temporaryResources'])) {
                    $temporary = $result['temporaryResources'];
                    $output->writeln('Temporary resources removed: ' . ($temporary['removedInputs'] + $temporary['removedNameLists'])
                        . '; fresh or future-dated resources retained: ' . ($temporary['retainedFreshInputs'] + $temporary['retainedFreshNameLists']) . '.');
                    if (!$temporary['ownershipVerified'] || $temporary['scanLimitReached'] || $temporary['deletionLimitReached'] || $temporary['scanFailed'] || ($temporary['cleanupFailed'] ?? false)) {
                        $output->writeln('Temporary cleanup was partial. Additional resources may remain under the one-hour retention rule.');
                    }
                }
            }
            else {
                $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                $output->writeln('Dry run only. Use --force to disable the app and permanently purge this metadata.');
            }
            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $message = $error instanceof \OCP\Lock\LockedException
                ? 'Archive Tools is in use. Wait for running tasks and downloads to finish before purging.'
                : ($error instanceof \RuntimeException ? $error->getMessage() : 'Archive Tools cleanup failed. Review the server log before retrying.');
            $output->writeln('<error>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</error>');
            return Command::FAILURE;
        }
    }
}
