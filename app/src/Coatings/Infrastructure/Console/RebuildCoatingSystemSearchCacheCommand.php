<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Console;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Infrastructure\Cache\CoatingSystemComplianceCacheRepository;
use App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:coating-system:rebuild-search-cache', description: 'Backfill: пересобрать coating_system_search и coating_system_compliance для всех систем.')]
final class RebuildCoatingSystemSearchCacheCommand extends Command
{
    public function __construct(
        private readonly CoatingSystemRepositoryInterface $repo,
        private readonly CoatingSystemSearchCacheRepository $searchCache,
        private readonly CoatingSystemComplianceCacheRepository $complianceCache,
        private readonly ComplianceEvaluator $evaluator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = 0;
        foreach ($this->repo->findAll() as $system) {
            $this->searchCache->upsert($system);
            $this->complianceCache->rewrite($system, $this->evaluator);
            ++$count;
        }
        $io->success(sprintf('Пересобрано систем: %d', $count));

        return Command::SUCCESS;
    }
}
