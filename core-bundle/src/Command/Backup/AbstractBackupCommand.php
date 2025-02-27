<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Command\Backup;

use Contao\CoreBundle\Doctrine\Backup\BackupManager;
use Contao\CoreBundle\Doctrine\Backup\Config\AbstractConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * @internal
 */
abstract class AbstractBackupCommand extends Command
{
    protected BackupManager $backupManager;

    public function __construct(BackupManager $backupManager)
    {
        $this->backupManager = $backupManager;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'The path to the SQL dump file')
            ->addOption('ignore-tables', 'i', InputOption::VALUE_OPTIONAL, 'A comma-separated list of database tables to ignore. Defaults to the backup configuration (contao.backup.ignore_tables). You can use the prefixes "+" and "-" to modify the existing configuration (e.g. "+tl_user" would add "tl_user" to the existing list).')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'The output format (txt, json)', 'txt')
        ;
    }

    /**
     * @template T of AbstractConfig
     *
     * @phpstan-param T $config
     *
     * @phpstan-return T
     */
    protected function handleCommonConfig(InputInterface $input, AbstractConfig $config): AbstractConfig
    {
        if ($file = $input->getArgument('file')) {
            $config = $config->withFilePath($file);
        }

        if ($tablesToIgnore = $input->getOption('ignore-tables')) {
            $config = $config->withTablesToIgnore(explode(',', $tablesToIgnore));
        }

        return $config;
    }

    protected function isJson(InputInterface $input): bool
    {
        $format = $input->getOption('format');

        if (!\in_array($format, ['json', 'txt'], true)) {
            throw new \InvalidArgumentException('This command only supports the "txt" and "json" formats.');
        }

        return 'json' === $format;
    }
}
