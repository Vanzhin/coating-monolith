<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Console;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
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
        private readonly ProfileComplianceRepositoryInterface $repository,
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
        $ids = null !== $one ? [(string) $one] : $this->repository->findAllProfileIds();

        foreach ($ids as $id) {
            $this->rebuilder->rebuildForProfile($id);
        }
        $output->writeln(sprintf('Пересобрано профилей: %d', count($ids)));

        return Command::SUCCESS;
    }
}
