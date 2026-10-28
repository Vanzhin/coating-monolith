<?php

declare(strict_types=1);

namespace App\Coatings\Application\Service;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Domain\Repository\CoatingSystemSort;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Coatings\Domain\Repository\ThermalEnvironment;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\RangeFilter;

/**
 * Собирает доменный CoatingSystemsFilter из примитивного входа: нормализует строки в
 * enum'ы (tryFrom — мусор молча в null), диапазоны и единицы. Общий для HTTP-списка и
 * подбора-по-параметрам, чтобы маппинг не дублировался. Compliance-каскад (category/
 * durability только при standard) — доменное правило в самом CoatingSystemsFilter.
 */
final class CoatingSystemsFilterBuilder
{
    public static function build(CoatingSystemsFilterInput $in): CoatingSystemsFilter
    {
        $substrates = array_values(array_filter(array_map(
            static fn (string $v): ?Substrate => Substrate::tryFrom($v),
            $in->substrates,
        )));

        return new CoatingSystemsFilter(
            search: SearchQuery::tryFromString($in->search),
            substrates: $substrates,
            environment: null !== $in->environment ? EnvironmentType::tryFrom($in->environment) : null,
            standard: null !== $in->standard ? ComplianceStandard::tryFrom($in->standard) : null,
            category: '' !== (string) $in->category ? $in->category : null,
            durability: '' !== (string) $in->durability ? $in->durability : null,
            tagIds: new StringCollection(...$in->tagIds),
            coatingIds: new StringCollection(...$in->coatingIds),
            substanceIds: new StringCollection(...$in->substanceIds),
            applicationMinTemp: self::range($in->applicationMinTempFrom, $in->applicationMinTempTo),
            minApplicationTimeAt20: self::range($in->minApplicationTimeFromMinutes, $in->minApplicationTimeToMinutes),
            sort: CoatingSystemSort::tryFrom((string) $in->sort) ?? CoatingSystemSort::DEFAULT,
            pager: Pager::fromPage(max(1, $in->page), $in->limit),
            hasDocuments: $in->hasDocuments,
            thermalTemperature: $in->thermalTemperature,
            thermalEnvironment: null !== $in->thermalEnvironment ? ThermalEnvironment::tryFrom($in->thermalEnvironment) : null,
            thermalIncludingPeak: $in->thermalIncludingPeak,
            substanceTemperature: null !== $in->substanceTemperature && $in->substanceTemperature > 0
                ? new PositiveNumber($in->substanceTemperature)
                : null,
        );
    }

    /** Инвертированный диапазон тихо роняем (политика списка систем), иначе RangeFilter. */
    private static function range(?int $from, ?int $to): ?RangeFilter
    {
        if (null !== $from && null !== $to && $from > $to) {
            return null;
        }

        return RangeFilter::tryFromNullable($from, $to);
    }
}
