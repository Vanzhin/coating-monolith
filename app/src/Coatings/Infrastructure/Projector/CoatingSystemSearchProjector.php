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
 * Обновляет две read-модели поиска систем при любом изменении CoatingSystem:
 *  1) `coating_system_compliance` — совпадения по стандартам (ISO 12944, NORSOK) для фильтра по требованиям.
 *  2) `coating_system_search`     — одна строка на систему: сумма мин.интервалов перекрытия при 20 °C
 *     по всем слоям кроме последнего, максимум мин.температуры нанесения по слоям и tsvector-документ
 *     (title + description + производители слоёв + теги).
 *
 * Placeholder-логика для `sum_min_recoat_20_minutes`: суммирует точки при 20 °C напрямую без учёта
 * интерполяции по фактической толщине слоя (шаг 5а плана 1 внедряет интерполяцию через
 * `RecoatingInterpolationModel`).
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

    public function rebuild(CoatingSystem $system, Connection $conn): void
    {
        $this->rebuildCompliance($system, $conn);
        $this->rebuildSearch($system, $conn);
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

    private function rebuildSearch(CoatingSystem $system, Connection $conn): void
    {
        $sumMinRecoat = $this->calculateSumMinRecoatAt20($system);
        $maxAppMinTemp = $this->calculateMaxApplicationMinTemp($system);
        $document = $this->buildSearchDocument($system);

        $conn->executeStatement(
            <<<'SQL'
                INSERT INTO coating_system_search
                    (system_id, sum_min_recoat_20_minutes, max_application_min_temp, search_tsvector)
                VALUES
                    (:id, :sum, :max_temp, to_tsvector('russian', :doc))
                ON CONFLICT (system_id) DO UPDATE
                SET sum_min_recoat_20_minutes = EXCLUDED.sum_min_recoat_20_minutes,
                    max_application_min_temp  = EXCLUDED.max_application_min_temp,
                    search_tsvector           = EXCLUDED.search_tsvector
                SQL,
            [
                'id' => $system->getId(),
                'sum' => $sumMinRecoat,
                'max_temp' => $maxAppMinTemp,
                'doc' => $document,
            ],
        );
    }

    private function calculateSumMinRecoatAt20(CoatingSystem $system): ?int
    {
        $layers = $system->getLayers()->toArray();
        $count = count($layers);
        if ($count < 2) {
            return 0 === $count ? null : 0;
        }

        $sum = 0;
        // Верхний слой не покрывается ничем — его мин.интервал перекрытия не участвует в сборке.
        for ($i = 0; $i < $count - 1; ++$i) {
            $point = $layers[$i]->getCoating()->getMinRecoatingInterval()->default->getPoint(20);
            if (null === $point || null === $point->timeInMinutes) {
                continue;
            }
            $sum += $point->timeInMinutes;
        }

        return $sum;
    }

    private function calculateMaxApplicationMinTemp(CoatingSystem $system): ?int
    {
        $layers = $system->getLayers()->toArray();
        if ([] === $layers) {
            return null;
        }
        $max = null;
        foreach ($layers as $layer) {
            $temp = $layer->getCoating()->getApplicationMinTemp();
            if (null === $max || $temp > $max) {
                $max = $temp;
            }
        }

        return $max;
    }

    private function buildSearchDocument(CoatingSystem $system): string
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
