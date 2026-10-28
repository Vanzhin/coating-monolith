<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO;

/**
 * Параметры подбора, извлечённые из текста, в терминах проекта. Значения уже провалидированы
 * парсером (значения вне enum отброшены в null/[]). Строки-значения (не enum-инстансы): в
 * доменный CoatingSystemsFilter превращаются маппером (план 2), чтобы этот DTO не зависел от
 * домена Coatings. Температуры сгруппированы: chemicalResistance (контакт с веществом) и
 * thermal (нагрев без химии), у обоих рабочая/пиковая.
 */
final readonly class ExtractedParameters
{
    /**
     * @param list<string> $substrates        значения Substrate (steel_carbon и пр.)
     * @param list<string> $specifiedCoatings покрытия, заданные заказчиком (продукт/ГОСТ-спека)
     */
    public function __construct(
        public array $substrates,
        public ?string $environment,          // EnvironmentType value
        public array $specifiedCoatings,
        public ?string $search,
        public ChemicalResistanceParams $chemicalResistance,
        public ThermalParams $thermal,
        public ?string $standard,             // ComplianceStandard value
        public ?string $category,             // IsoCorrosivityCategory value
        public ?string $durability,           // IsoDurability value
        public ?IntRangeDto $applicationMinTemp,
        public ?IntRangeDto $minApplicationTimeAt20,
    ) {
    }
}
