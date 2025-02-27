<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Command;

use Contao\CoreBundle\Doctrine\Backup\BackupManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Migration\MigrationCollection;
use Contao\CoreBundle\Migration\MigrationResult;
use Contao\InstallationBundle\Database\Installer;
use Symfony\Component\Config\Exception\FileLocatorFileNotFoundException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class MigrateCommand extends Command
{
    protected static $defaultName = 'contao:migrate';

    private MigrationCollection $migrations;
    private FileLocator $fileLocator;
    private string $projectDir;
    private ContaoFramework $framework;
    private BackupManager $backupManager;
    private ?Installer $installer;
    private ?SymfonyStyle $io = null;

    public function __construct(MigrationCollection $migrations, FileLocator $fileLocator, string $projectDir, ContaoFramework $framework, BackupManager $backupManager, Installer $installer = null)
    {
        $this->migrations = $migrations;
        $this->fileLocator = $fileLocator;
        $this->projectDir = $projectDir;
        $this->framework = $framework;
        $this->backupManager = $backupManager;
        $this->installer = $installer;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('with-deletes', null, InputOption::VALUE_NONE, 'Execute all database migrations including DROP queries. Can be used together with --no-interaction.')
            ->addOption('schema-only', null, InputOption::VALUE_NONE, 'Only update the database schema.')
            ->addOption('migrations-only', null, InputOption::VALUE_NONE, 'Only execute the migrations.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show pending migrations and schema updates without executing them.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'The output format (txt, ndjson)', 'txt')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Disable the database backup which is created by default before executing the migrations.')
            ->setDescription('Executes migrations and updates the database schema.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        if (!$input->getOption('dry-run') && !$input->getOption('no-backup')) {
            $this->backup($input);
        }

        if ('ndjson' !== $input->getOption('format')) {
            return $this->executeCommand($input);
        }

        try {
            return $this->executeCommand($input);
        } catch (\Throwable $exception) {
            $this->writeNdjson('error', [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }

        return 1;
    }

    private function backup(InputInterface $input): void
    {
        $asJson = 'ndjson' === $input->getOption('format');
        $config = $this->backupManager->createCreateConfig();

        if (!$asJson) {
            $this->io->info(sprintf(
                'Creating a database dump to "%s" with the default options. Use --no-backup to disable this feature.',
                $config->getBackup()->getFilepath()
            ));
        }

        try {
            $this->backupManager->create($config);

            if ($asJson) {
                $this->writeNdjson('backup-result', $config->getBackup()->toArray());
            }
        } catch (\Throwable $exception) {
            if ($asJson) {
                $this->writeNdjson('error', [
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ]);
            } else {
                $this->io->error($exception->getMessage());
            }
        }
    }

    private function executeCommand(InputInterface $input): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $asJson = 'ndjson' === $input->getOption('format');

        if (!\in_array($input->getOption('format'), ['txt', 'ndjson'], true)) {
            throw new InvalidOptionException(sprintf('Unsupported format "%s".', $input->getOption('format')));
        }

        if ($asJson && !$dryRun && $input->isInteractive()) {
            throw new InvalidOptionException('Use --no-interaction or --dry-run together with --format=ndjson');
        }

        if ($input->getOption('migrations-only')) {
            if ($input->getOption('schema-only')) {
                throw new InvalidOptionException('--migrations-only cannot be combined with --schema-only');
            }

            if ($input->getOption('with-deletes')) {
                throw new InvalidOptionException('--migrations-only cannot be combined with --with-deletes');
            }

            return $this->executeMigrations($dryRun, $asJson) ? 0 : 1;
        }

        if ($input->getOption('schema-only')) {
            return $this->executeSchemaDiff($dryRun, $asJson, $input->getOption('with-deletes')) ? 0 : 1;
        }

        if (!$this->executeMigrations($dryRun, $asJson)) {
            return 1;
        }

        if (!$this->executeSchemaDiff($dryRun, $asJson, $input->getOption('with-deletes'))) {
            return 1;
        }

        if (!$dryRun && !$this->executeMigrations(false, $asJson)) {
            return 1;
        }

        if (!$asJson) {
            $this->io->success('All migrations completed.');
        }

        return 0;
    }

    private function executeMigrations(bool $dryRun, bool $asJson): bool
    {
        while (true) {
            $first = true;

            foreach ($this->migrations->getPendingNames() as $migration) {
                if ($first) {
                    if (!$asJson) {
                        $this->io->section('Pending migrations');
                    }

                    $first = false;
                }

                if ($asJson) {
                    $this->writeNdjson('migration-pending', ['name' => $migration]);
                } else {
                    $this->io->writeln(' * '.$migration);
                }
            }

            $runOnceFiles = $this->getRunOnceFiles();

            if ($runOnceFiles) {
                trigger_deprecation('contao/core-bundle', '4.9', 'Using "runonce.php" files has been deprecated and will no longer work in Contao 5.0. Use the migration framework instead.');
            }

            foreach ($runOnceFiles as $file) {
                if ($first) {
                    if (!$asJson) {
                        $this->io->section('Pending migrations');
                    }

                    $first = false;
                }

                if ($asJson) {
                    $this->writeNdjson('migration-pending', ['name' => 'Runonce file: '.$file]);
                } else {
                    $this->io->writeln(' * Runonce file: '.$file);
                }
            }

            if ($first || $dryRun) {
                break;
            }

            if (!$asJson) {
                if (!$this->io->confirm('Execute the listed migrations?')) {
                    return false;
                }

                $this->io->section('Execute migrations');
            }

            $count = 0;

            /** @var MigrationResult $result */
            foreach ($this->migrations->run() as $result) {
                ++$count;

                if ($asJson) {
                    $this->writeNdjson('migration-result', [
                        'message' => $result->getMessage(),
                        'isSuccessful' => $result->isSuccessful(),
                    ]);
                } else {
                    $this->io->writeln(' * '.$result->getMessage());

                    if (!$result->isSuccessful()) {
                        $this->io->error('Migration failed');
                    }
                }
            }

            foreach ($this->getRunOnceFiles() as $file) {
                ++$count;

                $this->executeRunonceFile($file);

                if ($asJson) {
                    $this->writeNdjson('migration-result', [
                        'message' => 'Executed runonce file: '.$file,
                        'isSuccessful' => true,
                    ]);
                } else {
                    $this->io->writeln(' * Executed runonce file: '.$file);
                }
            }

            if (!$asJson) {
                $this->io->success('Executed '.$count.' migrations.');
            }
        }

        return true;
    }

    private function getRunOnceFiles(): array
    {
        try {
            $files = $this->fileLocator->locate('config/runonce.php', null, false);
        } catch (FileLocatorFileNotFoundException $e) {
            return [];
        }

        return array_map(fn ($path) => Path::makeRelative($path, $this->projectDir), $files);
    }

    private function executeRunonceFile(string $file): void
    {
        $this->framework->initialize();

        $filePath = Path::join($this->projectDir, $file);

        include $filePath;

        (new Filesystem())->remove($filePath);
    }

    private function executeSchemaDiff(bool $dryRun, bool $asJson, bool $withDeletesOption): bool
    {
        if (null === $this->installer) {
            $this->io->error('Service "contao_installation.database.installer" not found. The installation bundle needs to be installed in order to execute schema diff migrations.');

            return false;
        }

        $commandsByHash = [];

        while (true) {
            $this->installer->compileCommands();

            if (!$commands = $this->installer->getCommands(false)) {
                return true;
            }

            $hasNewCommands = \count(array_filter(
                array_keys($commands),
                static fn ($hash) => !isset($commandsByHash[$hash])
            ));

            if (!$hasNewCommands) {
                return true;
            }

            if (!$asJson) {
                $this->io->section('Pending database migrations');
            }

            $commandsByHash = $commands;

            if ($asJson) {
                $this->writeNdjson('schema-pending', [
                    'commands' => array_values($commandsByHash),
                ]);
            } else {
                $this->io->listing($commandsByHash);
            }

            if ($dryRun) {
                return true;
            }

            $options = $withDeletesOption
                ? ['yes, with deletes', 'no']
                : ['yes', 'yes, with deletes', 'no'];

            if ($asJson) {
                $answer = $options[0];
            } else {
                $answer = $this->io->choice('Execute the listed database updates?', $options, $options[0]);
            }

            if ('no' === $answer) {
                return false;
            }

            if (!$asJson) {
                $this->io->section('Execute database migrations');
            }

            $count = 0;
            $commandHashes = $this->getCommandHashes($commands, 'yes, with deletes' === $answer);

            do {
                $commandExecuted = false;
                $exceptions = [];

                foreach ($commandHashes as $key => $hash) {
                    if ($asJson) {
                        $this->writeNdjson('schema-execute', [
                            'command' => $commandsByHash[$hash],
                        ]);
                    } else {
                        $this->io->write(' * '.$commandsByHash[$hash]);
                    }

                    try {
                        $this->installer->execCommand($hash);

                        ++$count;
                        $commandExecuted = true;
                        unset($commandHashes[$key]);

                        if ($asJson) {
                            $this->writeNdjson('schema-result', [
                                'command' => $commandsByHash[$hash],
                                'isSuccessful' => true,
                            ]);
                        } else {
                            $this->io->writeln('');
                        }
                    } catch (\Throwable $e) {
                        $exceptions[] = $e;

                        if ($asJson) {
                            $this->writeNdjson('schema-result', [
                                'command' => $commandsByHash[$hash],
                                'isSuccessful' => false,
                                'message' => $e->getMessage(),
                            ]);
                        } else {
                            $this->io->writeln('......FAILED');
                        }
                    }
                }
            } while ($commandExecuted);

            if (!$asJson) {
                $this->io->success('Executed '.$count.' SQL queries.');

                if (\count($exceptions)) {
                    foreach ($exceptions as $exception) {
                        $this->io->error($exception->getMessage());
                    }
                }
            }

            if (\count($exceptions)) {
                return false;
            }
        }
    }

    private function getCommandHashes(array $commands, bool $withDrops): array
    {
        if (!$withDrops) {
            foreach ($commands as $hash => $command) {
                if (
                    preg_match('/^ALTER TABLE [^ ]+ DROP /', $command)
                    || (0 === strncmp($command, 'DROP ', 5) && 0 !== strncmp($command, 'DROP INDEX', 10))
                ) {
                    unset($commands[$hash]);
                }
            }
        }

        return array_keys($commands);
    }

    private function writeNdjson(string $type, array $data): void
    {
        $this->io->writeln(
            json_encode(
                array_merge(['type' => $type], $data, ['type' => $type]),
                JSON_INVALID_UTF8_SUBSTITUTE
            )
        );

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \JsonException(json_last_error_msg());
        }
    }
}
