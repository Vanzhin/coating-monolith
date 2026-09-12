<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\Coating\ThermalExposureLimits;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\RangeFilter;

/**
 * Полный набор фильтров для поиска систем покрытий. Все поля опциональны.
 * Compliance — мастер-каскад: category/durability имеют смысл только когда задан standard.
 */
final readonly class CoatingSystemsFilter
{
    // Compliance-каскад: заполняются в конструкторе с учётом standard (см. ниже).
    public ?string $category;
    public ?string $durability;

    /**
     * @param list<Substrate> $substrates
     */
    public function __construct(
        public ?SearchQuery $search = null,
        public array $substrates = [],
        public ?EnvironmentType $environment = null,
        public ?ComplianceStandard $standard = null,
        ?string $category = null,
        ?string $durability = null,
        public StringCollection $tagIds = new StringCollection(),
        // Покрытия в составе системы (OR: хотя бы одно из выбранных).
        public StringCollection $coatingIds = new StringCollection(),
        public ?RangeFilter $applicationMinTemp = null,
        public ?RangeFilter $minApplicationTimeAt20 = null,
        public CoatingSystemSort $sort = CoatingSystemSort::DEFAULT,
        public Pager $pager = new Pager(1, 20),
        // null — не фильтровать; true — только с документами; false — только без.
        public ?bool $hasDocuments = null,
        // Температурный фасет: «система держит T °C в среде E, опционально с учётом пика».
        // Активен, только когда заданы и temperature, и environment (см. hasThermalFacet).
        public ?int $thermalTemperature = null,
        public ?ThermalEnvironment $thermalEnvironment = null,
        public bool $thermalIncludingPeak = false,
    ) {
        ThermalExposureLimits::assertTemperatureInRange('фильтр', $thermalTemperature);

        // Compliance-каскад (домен-правило): category/durability имеют смысл только при заданном
        // standard — без него сбрасываем, откуда бы они ни пришли (в т.ч. из query-параметров).
        $this->category = null !== $standard ? $category : null;
        $this->durability = null !== $standard ? $durability : null;
    }

    /** Активен ли температурный фасет — заданы обе обязательные части. */
    public function hasThermalFacet(): bool
    {
        return null !== $this->thermalTemperature && null !== $this->thermalEnvironment;
    }
}
