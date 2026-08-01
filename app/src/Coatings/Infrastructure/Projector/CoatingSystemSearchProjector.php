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

/**
 * Поисковые read-модели, не относящиеся к самому агрегату:
 *  1) `coating_system_compliance` — совпадения по стандартам (ISO 12944, NORSOK) для фильтра по требованиям.
 *  2) `coating_system_search.search_tsvector` — tsvector-документ для FTS.
 *
 * Бизнес-величины системы (`minBuildingTimeAt20Minutes`, `maxLayerApplicationMinTemp`,
 * complianceMatches и т.п.) живут на самой CoatingSystem и пересчитываются в её `postMutate`.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class CoatingSystemSearchProjector
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
        $this->rebuildCompliance($system, $conn);
        $this->rebuildSearchIndex($system, $conn);
    }

    private function rebuildCompliance(CoatingSystem $system, Connection $conn): void
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

    private function rebuildSearchIndex(CoatingSystem $system, Connection $conn): void
    {
        $conn->executeStatement(
            <<<'SQL'
                INSERT INTO coating_system_search (system_id, search_tsvector)
                VALUES (:id, to_tsvector('russian', :doc))
                ON CONFLICT (system_id) DO UPDATE
                SET search_tsvector = EXCLUDED.search_tsvector
                SQL,
            [
                'id' => $system->getId(),
                'doc' => $this->buildFullTextSearchDocument($system),
            ],
        );
    }

    /**
     * Что попадает в tsvector — инфраструктурное решение (какие поля индексируем).
     * Собирается из публичных геттеров домена, без обращения к бизнес-правилам.
     */
    private function buildFullTextSearchDocument(CoatingSystem $system): string
    {
        $parts = [$system->getTitle(), $system->getDescription()];
        foreach ($system->getLayers() as $layer) {
            $parts[] = $layer->getCoating()->getManufacturer()->getTitle();
            $parts[] = $layer->getCoating()->getTitle();
        }
        foreach ($system->getTags() as $tag) {
            $parts[] = $tag->getTitle();
        }

        return implode(' ', array_filter($parts, static fn (string $p) => '' !== trim($p)));
    }
}
