<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

/**
 * Нейтральный ПРИМИТИВНЫЙ вход для сборки CoatingSystemsFilter. Один источник для двух
 * путей: HTTP-список (CoatingSystemListRequestMapper читает Request → этот DTO) и подбор
 * по параметрам (SearchCoatingSystemsQuery::fromParameters). Enum'ы и доменный фильтр
 * строит CoatingSystemsFilterBuilder — потребители передают строки/числа, домена не знают.
 * Время — в МИНУТАХ (канон домена); перевод из часов формы делает HTTP-маппер.
 */
final readonly class CoatingSystemsFilterInput
{
    /**
     * @param list<string> $substrates
     * @param list<string> $tagIds
     * @param list<string> $coatingIds
     * @param list<string> $substanceIds
     */
    public function __construct(
        public ?string $search = null,
        public array $substrates = [],
        public ?string $environment = null,
        public ?string $standard = null,
        public ?string $category = null,
        public ?string $durability = null,
        public array $tagIds = [],
        public array $coatingIds = [],
        public array $substanceIds = [],
        public ?int $applicationMinTempFrom = null,
        public ?int $applicationMinTempTo = null,
        public ?int $minApplicationTimeFromMinutes = null,
        public ?int $minApplicationTimeToMinutes = null,
        public ?int $thermalTemperature = null,
        public ?string $thermalEnvironment = null,
        public bool $thermalIncludingPeak = false,
        public ?int $substanceTemperature = null,
        public ?bool $hasDocuments = null,
        public ?string $sort = null,
        public int $page = 1,
        public int $limit = 20,
    ) {
    }
}
