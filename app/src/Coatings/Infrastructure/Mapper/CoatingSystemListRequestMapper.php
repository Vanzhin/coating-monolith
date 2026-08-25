<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Mapper;

use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Domain\Repository\CoatingSystemSort;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Query-параметры списка систем покрытий → CoatingSystemsFilter. Pure shape.
 * Инвертированный диапазон роняем в null (тихо, без ошибки — политика этого
 * списка, отличается от списка покрытий). Compliance-каскад: category/durability
 * осмысленны только при заданном standard.
 */
final class CoatingSystemListRequestMapper
{
    private const MINUTES_PER_HOUR = 60;
    private const DEFAULT_LIMIT = 20;

    public function __construct(private readonly QueryParams $query)
    {
    }

    public function filterFromRequest(Request $request): CoatingSystemsFilter
    {
        $q = trim((string) $request->query->get('q', ''));
        $standard = ComplianceStandard::tryFrom((string) $request->query->get('standard', ''));

        $substrates = array_values(array_filter(array_map(
            static fn (mixed $v): ?Substrate => Substrate::tryFrom((string) $v),
            $request->query->all('substrates'),
        )));

        return new CoatingSystemsFilter(
            search: '' !== $q ? SearchQuery::tryFromString($q) : null,
            substrates: $substrates,
            environment: EnvironmentType::tryFrom((string) $request->query->get('environment', '')),
            standard: $standard,
            category: null !== $standard ? ($request->query->get('category') ?: null) : null,
            durability: null !== $standard ? ($request->query->get('durability') ?: null) : null,
            tagIds: $this->query->stringCollection($request, 'tagIds'),
            coatingIds: $this->query->stringCollection(
                $request,
                'coatingIds',
                static fn (string $id): bool => Uuid::isValid($id),
            ),
            applicationMinTemp: $this->query->intRange($request, 'applicationMinTempFrom', 'applicationMinTempTo'),
            minApplicationTimeAt20: $this->query->intRange(
                $request,
                'minApplicationTimeAt20From',
                'minApplicationTimeAt20To',
                self::MINUTES_PER_HOUR,
            ),
            sort: CoatingSystemSort::tryFrom((string) $request->query->get('sort', '')) ?? CoatingSystemSort::DEFAULT,
            pager: Pager::fromPage(max(1, (int) $request->query->get('page', 1)), self::DEFAULT_LIMIT),
            hasDocuments: match ((string) $request->query->get('hasDocuments', '')) {
                '1' => true,
                '0' => false,
                default => null,
            },
        );
    }
}
