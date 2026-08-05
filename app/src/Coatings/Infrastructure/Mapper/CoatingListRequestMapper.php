<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Mapper;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\CoatingSort;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Coatings\Domain\Repository\ThermalEnvironment;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Component\HttpFoundation\Request;

/**
 * Query-параметры списка покрытий → доменный CoatingsFilter. Pure shape:
 * читает query, конвертирует UI-единицы в минуты, валидирует enum-значения.
 * Инварианты (границы диапазонов, температурный фасет) кидает домен при
 * конструировании фильтра — экшен ловит AppException и рендерит ошибку.
 */
final class CoatingListRequestMapper
{
    // UI задаёт интервал перекрытия: min в ЧАСАХ, max в ДНЯХ. Домен — в минутах.
    private const MINUTES_PER_HOUR = 60;
    private const MINUTES_PER_DAY = 1440;

    public function __construct(private readonly QueryParams $query)
    {
    }

    public function filterFromRequest(Request $request): CoatingsFilter
    {
        $search = $request->query->get('search');
        $thermEnvRaw = $request->query->get('thermEnv');
        $sortRaw = $request->query->get('sort');

        return new CoatingsFilter(
            search: SearchQuery::tryFromString(is_string($search) ? $search : null),
            manufacturerIds: $this->query->stringCollection($request, 'manufacturerIds'),
            pager: Pager::fromPage(
                $this->query->nullableInt($request, 'page'),
                $this->query->nullableInt($request, 'limit'),
            ),
            applicationMinTemp: $this->query->intRange($request, 'appMinTempFrom', 'appMinTempTo', dropInverted: false),
            volumeSolid: $this->query->intRange($request, 'volumeSolidFrom', 'volumeSolidTo', dropInverted: false),
            tagIds: $this->query->stringCollection($request, 'tagIds'),
            thermalTemperature: $this->query->nullableInt($request, 'thermTemp'),
            thermalEnvironment: is_string($thermEnvRaw) ? ThermalEnvironment::tryFrom($thermEnvRaw) : null,
            thermalIncludingPeak: $request->query->getBoolean('thermPeak'),
            sort: (is_string($sortRaw) ? CoatingSort::tryFrom($sortRaw) : null) ?? CoatingSort::DEFAULT,
            baseValues: $this->query->stringCollection(
                $request,
                'baseValues',
                static fn (string $v): bool => null !== CoatingBase::tryFrom($v),
                unique: true,
            ),
            minRecoating20: $this->query->intRange($request, 'minRecoat20From', 'minRecoat20To', self::MINUTES_PER_HOUR, false),
            maxRecoating20: $this->query->intRange($request, 'maxRecoat20From', 'maxRecoat20To', self::MINUTES_PER_DAY, false),
        );
    }
}
