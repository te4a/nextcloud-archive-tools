<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Command;

use OCA\ArchiveTools\Archive\ArchiveReader;
use OCA\ArchiveTools\Archive\ProcessStream;
use OCA\ArchiveTools\Service\SettingsService;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Operational report without credentials, user paths, owners or member names. */
final class Diagnose extends Command {
    public function __construct(private ArchiveReader $reader, private SettingsService $settings, private IConfig $config) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('archive_tools:diagnose')
            ->setDescription('Report archive formats, runtime requirements and background-job health without changing settings.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $format = $input->getOption('output');
        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>Output format must be text or json.</error>');
            return Command::INVALID;
        }
        $lastCron = (int)$this->config->getAppValue('core', 'lastcron', '0');
        $mode = $this->config->getAppValue('core', 'backgroundjobs_mode', 'ajax');
        $age = $lastCron > 0 ? max(0, time() - $lastCron) : null;
        $warnings = [];
        if ($mode !== 'cron') { $warnings[] = 'Set Nextcloud background jobs to cron mode.'; }
        if ($age === null || $age > 900) { $warnings[] = 'No recent Nextcloud cron execution was recorded. Check the scheduler.'; }
        $archiveFormats = $this->reader->supportedFormats();
        $missingFormats = array_diff(['7z', 'rar', 'tar.bz2', 'tar.xz'], $archiveFormats);
        if ($missingFormats !== []) {
            $hint = match (true) {
                !ProcessStream::available() => 'Check util-linux (/usr/bin/prlimit) and the PHP process functions proc_open, proc_get_status, proc_terminate and proc_close in the web and cron environments.',
                !function_exists('link') && !function_exists('symlink') => 'Enable PHP link or symlink for isolated decoder input in the web and cron environments.',
                default => 'Check bundled 7-Zip execution or install a full system 7-Zip with the required codecs in the web and cron environments.',
            };
            $warnings[] = 'Unavailable archive formats: ' . implode(', ', $missingFormats) . '. ' . $hint;
        }
        $data = [
            'applicationVersion' => $this->config->getAppValue('archive_tools', 'installed_version', ''),
            'phpVersion' => PHP_VERSION,
            'databaseType' => $this->config->getSystemValue('dbtype', 'unknown'),
            'extensions' => ['intl' => extension_loaded('intl'), 'zip' => extension_loaded('zip'), 'zlib' => extension_loaded('zlib')],
            'archiveFormats' => $archiveFormats,
            'backgroundJobs' => ['mode' => $mode, 'lastRunAgeSeconds' => $age],
            'settings' => $this->settings->getSettings(),
            'warnings' => $warnings,
        ];
        if ($format === 'json') {
            $output->writeln(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln('Archive Tools ' . $data['applicationVersion'] . '; PHP ' . PHP_VERSION . '; database ' . $data['databaseType']);
            $output->writeln('Available formats: ' . implode(', ', $data['archiveFormats']));
            $output->writeln('Background jobs: ' . $mode . '; last run age: ' . ($age === null ? 'unknown' : $age . ' seconds'));
            foreach ($warnings as $warning) { $output->writeln('<comment>' . $warning . '</comment>'); }
        }
        return Command::SUCCESS;
    }
}
