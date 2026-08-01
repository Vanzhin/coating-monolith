<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Console;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'coatings:system-compliance:rebuild', description: 'Recompute coating_system_compliance index for all systems.')]
final class RebuildCoatingSystemComplianceCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ComplianceEvaluator $evaluator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('TRUNCATE TABLE coating_system_compliance');

        $qb = $this->em->createQueryBuilder();
        $qb->select('s')
            ->from(CoatingSystem::class, 's')
            ->leftJoin('s.layers', 'l')
            ->leftJoin('l.coating', 'c')
            ->addSelect('l')
            ->addSelect('c');

        $systems = $qb->getQuery()->getResult();

        $count = 0;
        foreach ($systems as $system) {
            /** @var CoatingSystem $system */
            $matches = $this->evaluator->evaluate($system);
            foreach ($matches as $m) {
                $conn->executeStatement(
                    'INSERT INTO coating_system_compliance (system_id, standard, category, durability)
                     VALUES (:id, :std, :cat, :dur)',
                    [
                        'id' => $system->getId(),
                        'std' => $m->standard->value,
                        'cat' => $m->category,
                        'dur' => $m->durability,
                    ],
                );
            }
            ++$count;
        }
        $output->writeln(sprintf('Rebuilt compliance for %d systems.', $count));

        return Command::SUCCESS;
    }
}
