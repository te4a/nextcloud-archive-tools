<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Command;

use OCA\ArchiveTools\Service\AdmissionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Bounded, explicit terminal-metadata recovery; never opens a user file. */
final class ClearHistory extends Command {
    public function __construct(private AdmissionService $admission) { parent::__construct(); }

    protected function configure(): void {
        $this->setName('archive_tools:clear-history')
            ->setDescription('Preview up to 200 terminal task records for one explicit owner; --execute forgets only this metadata, preserving source and output files.')
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Exact task owner uid (required).')
            ->addOption('id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Select specific task ids; repeat for multiple ids.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Permanently forget the selected terminal metadata, including retry selections and output references.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $owner = $input->getOption('owner');
        $format = $input->getOption('output');
        $ids = [];
        foreach ($input->getOption('id') as $id) {
            if (!is_string($id) || !preg_match('/^[1-9][0-9]*$/D', $id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                $output->writeln('<error>Select positive task ids.</error>'); return Command::INVALID;
            }
            $ids[] = (int)$id;
        }
        if (!is_string($owner) || $owner === '' || !in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>An explicit --owner and output format text or json are required.</error>'); return Command::INVALID;
        }
        try {
            $result = $this->admission->clearTerminalHistory($owner, (bool)$input->getOption('execute'), array_values(array_unique($ids)));
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            if ($format === 'text') {
                $output->writeln($result['execute']
                    ? 'Only terminal task metadata was forgotten. Source archives, output files and notifications were preserved.'
                    : 'Preview only. Use --execute to forget this terminal metadata and its retry selections. Source archives, output files and notifications remain unchanged.');
                if ($result['moreAvailable']) { $output->writeln('More terminal history remains; repeat the command for the next batch.'); }
            }
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Task history cleanup failed. Verify the explicit owner and task ids, then retry when Archive Tools is available.</error>');
            return Command::FAILURE;
        }
    }
}
