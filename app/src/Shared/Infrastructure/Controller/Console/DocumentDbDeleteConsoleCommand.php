<?php

namespace App\Shared\Infrastructure\Controller\Console;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Command\UseCase\Command\DeleteDb\DeleteDbCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:document:db-delete',
    description: 'es document db delete command',
)]
final class DocumentDbDeleteConsoleCommand extends Command
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $command = new DeleteDbCommand('documents');
        $result = $this->commandBus->execute($command);
        $result ? $io->success('DB deleted') : $io->error('Error DB deletion');

        return Command::SUCCESS;
    }
}
