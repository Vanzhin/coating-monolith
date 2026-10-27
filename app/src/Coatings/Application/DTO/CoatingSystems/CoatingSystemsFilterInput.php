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

    /**
     * Плоская сериализация примитивов. Используется внешними контекстами (Inquiries), чтобы
     * хранить распознанные параметры как непрозрачный массив и ре-прогонять поиск без потерь.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'substrates' => $this->substrates,
            'environment' => $this->environment,
            'standard' => $this->standard,
            'category' => $this->category,
            'durability' => $this->durability,
            'tagIds' => $this->tagIds,
            'coatingIds' => $this->coatingIds,
            'substanceIds' => $this->substanceIds,
            'applicationMinTempFrom' => $this->applicationMinTempFrom,
            'applicationMinTempTo' => $this->applicationMinTempTo,
            'minApplicationTimeFromMinutes' => $this->minApplicationTimeFromMinutes,
            'minApplicationTimeToMinutes' => $this->minApplicationTimeToMinutes,
            'thermalTemperature' => $this->thermalTemperature,
            'thermalEnvironment' => $this->thermalEnvironment,
            'thermalIncludingPeak' => $this->thermalIncludingPeak,
            'substanceTemperature' => $this->substanceTemperature,
            'hasDocuments' => $this->hasDocuments,
            'sort' => $this->sort,
            'page' => $this->page,
            'limit' => $this->limit,
        ];
    }

    /**
     * @param array<string, mixed> $d
     */
    public static function fromArray(array $d): self
    {
        return new self(
            search: self::asString($d, 'search'),
            substrates: self::asStringList($d, 'substrates'),
            environment: self::asString($d, 'environment'),
            standard: self::asString($d, 'standard'),
            category: self::asString($d, 'category'),
            durability: self::asString($d, 'durability'),
            tagIds: self::asStringList($d, 'tagIds'),
            coatingIds: self::asStringList($d, 'coatingIds'),
            substanceIds: self::asStringList($d, 'substanceIds'),
            applicationMinTempFrom: self::asInt($d, 'applicationMinTempFrom'),
            applicationMinTempTo: self::asInt($d, 'applicationMinTempTo'),
            minApplicationTimeFromMinutes: self::asInt($d, 'minApplicationTimeFromMinutes'),
            minApplicationTimeToMinutes: self::asInt($d, 'minApplicationTimeToMinutes'),
            thermalTemperature: self::asInt($d, 'thermalTemperature'),
            thermalEnvironment: self::asString($d, 'thermalEnvironment'),
            thermalIncludingPeak: self::asBool($d, 'thermalIncludingPeak'),
            substanceTemperature: self::asInt($d, 'substanceTemperature'),
            hasDocuments: isset($d['hasDocuments']) ? self::asBool($d, 'hasDocuments') : null,
            sort: self::asString($d, 'sort'),
            page: self::asInt($d, 'page') ?? 1,
            limit: self::asInt($d, 'limit') ?? 20,
        );
    }

    /** @param array<string, mixed> $d */
    private static function asString(array $d, string $key): ?string
    {
        return isset($d[$key]) && is_string($d[$key]) ? $d[$key] : null;
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return list<string>
     */
    private static function asStringList(array $d, string $key): array
    {
        if (!isset($d[$key]) || !is_array($d[$key])) {
            return [];
        }
        $out = [];
        foreach ($d[$key] as $v) {
            if (is_string($v)) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $d */
    private static function asInt(array $d, string $key): ?int
    {
        return isset($d[$key]) && is_int($d[$key]) ? $d[$key] : null;
    }

    /** @param array<string, mixed> $d */
    private static function asBool(array $d, string $key): bool
    {
        return isset($d[$key]) && true === $d[$key];
    }
}
