<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\ManagerBundle\ContaoManager\ApiCommand;

use Contao\ManagerBundle\Api\Application;
use Contao\ManagerBundle\Dotenv\DotenvDumper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;

/**
 * @internal
 */
class SetDotEnvCommand extends Command
{
    private string $projectDir;

    public function __construct(Application $application)
    {
        parent::__construct();

        $this->projectDir = $application->getProjectDir();
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('dot-env:set')
            ->setDescription('Writes a parameter to the .env file.')
            ->addArgument('key', InputArgument::REQUIRED, 'The variable name')
            ->addArgument('value', InputArgument::REQUIRED, 'The new value')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dotenv = new DotenvDumper(Path::join($this->projectDir, '.env'));
        $dotenv->setParameter($input->getArgument('key'), $input->getArgument('value'));
        $dotenv->dump();

        return 0;
    }
}
