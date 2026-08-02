<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQuery;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Domain\Repository\CoatingSystemSort;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\RangeFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/coating/coating-system/list',
    name: 'app_cabinet_coating_system_list',
    methods: ['GET'],
)]
final class ListAction extends AbstractController
{
    private const MINUTES_PER_HOUR = 60;
    private const DEFAULT_LIMIT = 20;

    public function __invoke(Request $request, QueryBusInterface $queryBus): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $substrates = array_values(array_filter(array_map(
            static fn (mixed $v) => Substrate::tryFrom((string) $v),
            (array) $request->query->all('substrates'),
        )));
        $standard = ComplianceStandard::tryFrom((string) $request->query->get('standard', ''));
        $category = $request->query->get('category') ?: null;
        $durability = $request->query->get('durability') ?: null;
        $tagIds = array_values(array_filter(array_map('strval', (array) $request->query->all('tagIds'))));
        $applicationMinTemp = $this->readRange($request, 'applicationMinTempFrom', 'applicationMinTempTo');
        $minApplicationTimeAt20 = $this->readRange(
            $request,
            'minApplicationTimeAt20From',
            'minApplicationTimeAt20To',
            multiplier: self::MINUTES_PER_HOUR,
        );
        $sort = CoatingSystemSort::tryFrom((string) $request->query->get('sort', '')) ?? CoatingSystemSort::DEFAULT;
        $page = max(1, (int) $request->query->get('page', 1));
        $partial = $request->query->getBoolean('partial', false);

        $search = '' !== $q ? SearchQuery::tryFromString($q) : null;

        $filter = new CoatingSystemsFilter(
            search: $search,
            substrates: $substrates,
            standard: $standard,
            category: null !== $standard ? $category : null,
            durability: null !== $standard ? $durability : null,
            tagIds: $tagIds,
            applicationMinTemp: $applicationMinTemp,
            minApplicationTimeAt20: $minApplicationTimeAt20,
            sort: $sort,
            pager: Pager::fromPage($page, self::DEFAULT_LIMIT),
        );

        /** @var SearchCoatingSystemsQueryResult $result */
        $result = $queryBus->execute(new SearchCoatingSystemsQuery($filter));

        $template = $partial
            ? 'cabinet/coating/coating_system/_list_cards.html.twig'
            : 'cabinet/coating/coating_system/list.html.twig';

        return $this->render($template, [
            'items' => $result->items,
            'total' => $result->total,
            'q' => $q,
            'substrates' => $substrates,
            'standard' => $standard,
            'category' => $category,
            'durability' => $durability,
            'tagIds' => $tagIds,
            'applicationMinTemp' => $applicationMinTemp,
            'minApplicationTimeAt20Hours' => $this->rangeToHours($minApplicationTimeAt20),
            'sort' => $sort,
            'page' => $page,
            'perPage' => self::DEFAULT_LIMIT,
            'sortOptions' => CoatingSystemSort::cases(),
            'substrateOptions' => Substrate::cases(),
            'standardOptions' => ComplianceStandard::cases(),
        ]);
    }

    private function readRange(Request $request, string $fromKey, string $toKey, int $multiplier = 1): ?RangeFilter
    {
        $fromRaw = $request->query->get($fromKey);
        $toRaw = $request->query->get($toKey);

        $from = ('' !== (string) $fromRaw && null !== $fromRaw) ? ((int) $fromRaw) * $multiplier : null;
        $to = ('' !== (string) $toRaw && null !== $toRaw) ? ((int) $toRaw) * $multiplier : null;

        // Инвертированный диапазон (from > to) игнорируем, возвращаем null.
        // Это безопаснее, чем свапать или кидать ошибку в 500.
        if (null !== $from && null !== $to && $from > $to) {
            return null;
        }

        return RangeFilter::tryFromNullable($from, $to);
    }

    /** @return array{from: int, to: int}|null */
    private function rangeToHours(?RangeFilter $range): ?array
    {
        if (null === $range) {
            return null;
        }

        return [
            'from' => null !== $range->from ? (int) round($range->from / self::MINUTES_PER_HOUR) : 0,
            'to' => null !== $range->to ? (int) round($range->to / self::MINUTES_PER_HOUR) : 0,
        ];
    }
}
