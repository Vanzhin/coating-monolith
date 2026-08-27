<?php

declare(strict_types=1);

namespace App\Coatings\Application\Service;

use App\Coatings\Application\DTO\Coatings\ThermalExposureLimitsDTO;
use App\Coatings\Application\UseCase\Query\GetCoatingsByIds\GetCoatingsByIdsQuery;
use App\Coatings\Application\UseCase\Query\GetCoatingsByIds\GetCoatingsByIdsQueryResult;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

/**
 * Считает снапшот максимальной температуры эксплуатации системы (слабое звено — min по слоям).
 * Источник данных о пределах — покрытия (единая точка правды): берём их read-моделью через
 * переиспользование запроса GetCoatingsByIds, а не лезем в БД напрямую. Дефолт по основе для
 * сухого тепла уже применён в Coating::getDryHeatExposure() и дотекает сюда через CoatingDTO.
 */
final readonly class CoatingSystemOperatingTemperatureCalculator
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    public function calculate(CoatingSystem $system): OperatingTemperatureSnapshot
    {
        $coatingIds = [];
        foreach ($system->getLayers() as $layer) {
            $coatingIds[] = $layer->getCoating()->getId();
        }
        if ([] === $coatingIds) {
            return new OperatingTemperatureSnapshot(null, null, null, null);
        }

        /** @var GetCoatingsByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetCoatingsByIdsQuery(new StringCollection(...$coatingIds)));

        $dryContinuous = [];
        $dryPeak = [];
        $immersionContinuous = [];
        $immersionPeak = [];
        foreach ($result->coatings as $coating) {
            $dry = $coating->dryHeatExposure;         // всегда есть: дефолт по основе применён в геттере
            $immersion = $coating->immersionExposure;  // может быть null: погружение не задокументировано
            $dryContinuous[] = $dry?->continuous_max;
            $dryPeak[] = $this->upperBound($dry);
            $immersionContinuous[] = $immersion?->continuous_max;
            $immersionPeak[] = $this->upperBound($immersion);
        }

        return new OperatingTemperatureSnapshot(
            $this->weakestLink($dryContinuous),
            $this->weakestLink($dryPeak),
            $this->weakestLink($immersionContinuous),
            $this->weakestLink($immersionPeak),
        );
    }

    /** Верхняя граница с учётом пика: peak_max, иначе continuous_max. */
    private function upperBound(?ThermalExposureLimitsDTO $limits): ?int
    {
        return $limits?->peak_max ?? $limits?->continuous_max;
    }

    /**
     * Слабое звено: если список пуст ИЛИ хоть у одного слоя значение неизвестно (null) —
     * снапшот неизвестен (null); иначе минимум по слоям.
     *
     * @param list<int|null> $values
     */
    private function weakestLink(array $values): ?int
    {
        if ([] === $values) {
            return null;
        }
        foreach ($values as $value) {
            if (null === $value) {
                return null;
            }
        }

        return min($values);
    }
}
