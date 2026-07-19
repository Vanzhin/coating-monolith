<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\Coating\ThermalExposureLimits;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\RangeFilter;

/**
 * Bag-of-fields для поискового запроса + фасетов. Собственной логики не носит —
 * инварианты полей живут в узких VO (SearchQuery, RangeFilter, StringCollection,
 * ThermalExposureLimits::assertTemperatureInRange). Здесь только одна проверка
 * границ температурного фасета через делегирование в домен.
 */
readonly class CoatingsFilter
{
    public function __construct(
        public ?SearchQuery $search = null,
        public StringCollection $manufacturerIds = new StringCollection(),
        public ?Pager $pager = null,
        public ?RangeFilter $applicationMinTemp = null,
        public ?RangeFilter $volumeSolid = null,
        public StringCollection $tagIds = new StringCollection(),
        // Температурный фасет: «покрытие держит T °C в среде E, опционально с
        // учётом пика». Фасет активен, только когда заданы и temperature, и
        // environment (см. CoatingFinder::applyThermalExposureFacet).
        public ?int $thermalTemperature = null,
        public ?ThermalEnvironment $thermalEnvironment = null,
        public bool $thermalIncludingPeak = false,
        public CoatingSort $sort = CoatingSort::DEFAULT,
        // Тип связующего (ISO 12944-5): 'AK', 'AY', 'ESI', 'EP', 'PUR', 'FEVE', 'PAS', 'PS'.
        // Multi-value OR-семантика: покрытие подходит, если его base в этом списке.
        // Пустая коллекция — фасет не применяется.
        public StringCollection $baseValues = new StringCollection(),
        // Интервал перекрытия при +20 °C в минутах. min всегда есть у покрытия,
        // max — nullable (у покрытий без верхней границы вылетают из выборки при
        // активном maxRecoating20). Значение при 20 °C интерполируется в SQL
        // (см. RECOATING_AT_20C DQL-функцию, зеркалит DryingTimeSeries::getPoint).
        public ?RangeFilter $minRecoating20 = null,
        public ?RangeFilter $maxRecoating20 = null,
    ) {
        ThermalExposureLimits::assertTemperatureInRange('фильтр', $thermalTemperature);
    }

    /** Активен ли температурный фасет — заданы обе обязательные части. */
    public function hasThermalFacet(): bool
    {
        return null !== $this->thermalTemperature && null !== $this->thermalEnvironment;
    }
}
