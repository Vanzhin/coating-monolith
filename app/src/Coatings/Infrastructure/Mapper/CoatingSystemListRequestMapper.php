<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Mapper;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Coatings\Application\Service\CoatingSystemsFilterBuilder;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Shared\Domain\Aggregate\ValueObject\Duration;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Query-параметры списка систем покрытий → CoatingSystemsFilter. Pure shape: читает query в
 * примитивный CoatingSystemsFilterInput, доменный фильтр из него строит общий
 * CoatingSystemsFilterBuilder (один маппинг для HTTP-списка и подбора-по-параметрам).
 */
final class CoatingSystemListRequestMapper
{
    private const DEFAULT_LIMIT = 20;

    public function __construct(private readonly QueryParams $query)
    {
    }

    public function filterFromRequest(Request $request): CoatingSystemsFilter
    {
        // `search` — основной параметр; `q` — fallback для старых закладок/ссылок.
        $searchRaw = $request->query->get('search') ?? $request->query->get('q');
        $thermEnvRaw = $request->query->get('thermEnv');

        // UI задаёт время в ЧАСАХ, домен — в минутах; перевод здесь, билдер уже получает минуты.
        $timeFrom = $this->query->nullableInt($request, 'minApplicationTimeAt20From');
        $timeTo = $this->query->nullableInt($request, 'minApplicationTimeAt20To');

        return CoatingSystemsFilterBuilder::build(new CoatingSystemsFilterInput(
            search: null !== $searchRaw ? (string) $searchRaw : null,
            substrates: array_values(array_filter(
                $request->query->all('substrates'),
                static fn (mixed $v): bool => \is_string($v),
            )),
            environment: $this->nonEmpty($request->query->get('environment')),
            standard: $this->nonEmpty($request->query->get('standard')),
            category: $this->nonEmpty($request->query->get('category')),
            durability: $this->nonEmpty($request->query->get('durability')),
            tagIds: $this->query->stringCollection($request, 'tagIds')->getList(),
            coatingIds: $this->query->stringCollection(
                $request,
                'coatingIds',
                static fn (string $id): bool => Uuid::isValid($id),
            )->getList(),
            substanceIds: $this->query->stringCollection(
                $request,
                'substanceIds',
                static fn (string $id): bool => Uuid::isValid($id),
            )->getList(),
            applicationMinTempFrom: $this->query->nullableInt($request, 'applicationMinTempFrom'),
            applicationMinTempTo: $this->query->nullableInt($request, 'applicationMinTempTo'),
            minApplicationTimeFromMinutes: null !== $timeFrom ? $timeFrom * Duration::MINUTES_PER_HOUR : null,
            minApplicationTimeToMinutes: null !== $timeTo ? $timeTo * Duration::MINUTES_PER_HOUR : null,
            thermalTemperature: $this->query->nullableInt($request, 'thermTemp'),
            thermalEnvironment: \is_string($thermEnvRaw) ? $thermEnvRaw : null,
            thermalIncludingPeak: (bool) $request->query->get('thermPeak'),
            substanceTemperature: $this->query->nullableInt($request, 'substanceTemp'),
            hasDocuments: match ((string) $request->query->get('hasDocuments', '')) {
                '1' => true,
                '0' => false,
                default => null,
            },
            sort: $this->nonEmpty($request->query->get('sort')),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: self::DEFAULT_LIMIT,
        ));
    }

    private function nonEmpty(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
