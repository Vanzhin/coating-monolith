<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Mapper;

use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Domain\Repository\CoatingSystemSort;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Coatings\Domain\Repository\ThermalEnvironment;
use App\Shared\Domain\Aggregate\ValueObject\Duration;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Query-параметры списка систем покрытий → CoatingSystemsFilter. Pure shape: читает query,
 * нормализует единицы/enum'ы. Инвертированный диапазон роняем в null (тихо, без ошибки —
 * политика этого списка). Compliance-каскад (category/durability осмысленны только при
 * заданном standard) — доменное правило, живёт в CoatingSystemsFilter.
 */
final class CoatingSystemListRequestMapper
{
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

        $thermEnvRaw = $request->query->get('thermEnv');

        return new CoatingSystemsFilter(
            search: '' !== $q ? SearchQuery::tryFromString($q) : null,
            substrates: $substrates,
            environment: EnvironmentType::tryFrom((string) $request->query->get('environment', '')),
            standard: $standard,
            // Пусто → null (форм-нормализация); каскад «только при заданном standard» —
            // доменное правило в CoatingSystemsFilter, здесь пробрасываем как есть.
            category: $request->query->get('category') ?: null,
            durability: $request->query->get('durability') ?: null,
            tagIds: $this->query->stringCollection($request, 'tagIds'),
            coatingIds: $this->query->stringCollection(
                $request,
                'coatingIds',
                static fn (string $id): bool => Uuid::isValid($id),
            ),
            applicationMinTemp: $this->query->intRange($request, 'applicationMinTempFrom', 'applicationMinTempTo'),
            // UI задаёт время в ЧАСАХ, домен — в минутах; множитель из Duration.
            minApplicationTimeAt20: $this->query->intRange(
                $request,
                'minApplicationTimeAt20From',
                'minApplicationTimeAt20To',
                Duration::MINUTES_PER_HOUR,
            ),
            sort: CoatingSystemSort::tryFrom((string) $request->query->get('sort', '')) ?? CoatingSystemSort::DEFAULT,
            pager: Pager::fromPage(max(1, (int) $request->query->get('page', 1)), self::DEFAULT_LIMIT),
            hasDocuments: match ((string) $request->query->get('hasDocuments', '')) {
                '1' => true,
                '0' => false,
                default => null,
            },
            thermalTemperature: $this->query->nullableInt($request, 'thermTemp'),
            thermalEnvironment: is_string($thermEnvRaw) ? ThermalEnvironment::tryFrom($thermEnvRaw) : null,
            thermalIncludingPeak: (bool) $request->query->get('thermPeak'),
        );
    }
}
