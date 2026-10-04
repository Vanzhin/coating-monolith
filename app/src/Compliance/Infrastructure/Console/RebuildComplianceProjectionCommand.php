<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Console;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Служебная пересборка проекции учёта — страховка при пропущенном событии/дрейфе данных.
 * Без аргумента — все заведённые учёты; с profileId — один.
 */
#[AsCommand(name: 'app:compliance:rebuild-projection', description: 'Пересобрать проекцию учёта Compliance.')]
final class RebuildComplianceProjectionCommand extends Command
{
    public function __construct(
        private readonly ComplianceProjectionRebuilder $rebuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('profileId', InputArgument::OPTIONAL, 'profileId (пусто = все заведённые учёты)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $one = $input->getArgument('profileId');
        if (null !== $one) {
            $this->rebuilder->rebuild(profileIds: new StringCollection((string) $one));
            $output->writeln('Пересобран профиль: '.$one);
        } else {
            $this->rebuilder->rebuild();
            $output->writeln('Пересобраны все заведённые учёты.');
        }

        return Command::SUCCESS;
    }
}
