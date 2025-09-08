<?php

namespace App\Command;

use App\Service\SettingProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:setting:set', description: 'Set or update a setting key')]
class SettingSetCommand extends Command
{
    public function __construct(private readonly SettingProvider $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Key (ex: site.phone)')
            ->addArgument('value', InputArgument::REQUIRED, 'Value');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->settings->set($input->getArgument('name'), $input->getArgument('value'));
        $output->writeln('<info>✔ Setting saved.</info>');
        return Command::SUCCESS;
    }
}
