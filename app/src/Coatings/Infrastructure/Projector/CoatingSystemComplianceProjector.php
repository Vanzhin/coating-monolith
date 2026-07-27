<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Projector;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class CoatingSystemComplianceProjector
{
    public function __construct(private readonly ComplianceEvaluator $evaluator)
    {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof CoatingSystem) {
            return;
        }
        $this->rebuild($entity, $args->getObjectManager()->getConnection());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof CoatingSystem) {
            return;
        }
        $this->rebuild($entity, $args->getObjectManager()->getConnection());
    }

    private function rebuild(CoatingSystem $system, Connection $conn): void
    {
        $conn->executeStatement(
            'DELETE FROM coating_system_compliance WHERE system_id = :id',
            ['id' => $system->getId()],
        );
        $matches = $this->evaluator->evaluate($system);
        foreach ($matches as $m) {
            $conn->executeStatement(
                'INSERT INTO coating_system_compliance (system_id, standard, category, durability)
                 VALUES (:id, :std, :cat, :dur)',
                [
                    'id' => $system->getId(),
                    'std' => $m['standard']->value,
                    'cat' => $m['category'],
                    'dur' => $m['durability'],
                ],
            );
        }
    }
}
