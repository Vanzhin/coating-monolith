<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchSystemsAndCoatings;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterAxis;
use App\Coatings\Application\Service\CoatingSystemsFilterBuilder;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQuery;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQuery;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\CoatingSort;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

final readonly class SearchSystemsAndCoatingsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    public function __invoke(SearchSystemsAndCoatingsQuery $query): SearchSystemsAndCoatingsQueryResult
    {
        $systemFilter = CoatingSystemsFilterBuilder::build($query->input);
        /** @var SearchCoatingSystemsQueryResult $systems */
        $systems = $this->queryBus->execute(new SearchCoatingSystemsQuery($systemFilter));

        $coatings = null;
        if ($this->hasCoatingConditions($systemFilter)) {
            /** @var GetPagedCoatingsQueryResult $coatings */
            $coatings = $this->queryBus->execute(new GetPagedCoatingsQuery(new CoatingsFilter(
                search: $systemFilter->search,
                pager: Pager::fromPage(max(1, $query->coatingsPage), $systemFilter->pager->perPage),
                applicationMinTemp: $systemFilter->applicationMinTemp,
                tagIds: $systemFilter->tagIds,
                thermalTemperature: $systemFilter->thermalTemperature,
                thermalEnvironment: $systemFilter->thermalEnvironment,
                thermalIncludingPeak: $systemFilter->thermalIncludingPeak,
                sort: CoatingSort::tryFrom($systemFilter->sort->value) ?? CoatingSort::DEFAULT,
                coatingIds: $systemFilter->coatingIds,
                substanceIds: $systemFilter->substanceIds,
                substanceTemperature: $systemFilter->substanceTemperature,
            )));
        }

        return new SearchSystemsAndCoatingsQueryResult($systems, $coatings, $this->uncheckedConditions($systemFilter));
    }

    private function hasCoatingConditions(CoatingSystemsFilter $filter): bool
    {
        return null !== $filter->search
            || 0 !== $filter->tagIds->count()
            || 0 !== $filter->coatingIds->count()
            || 0 !== $filter->substanceIds->count()
            || null !== $filter->applicationMinTemp
            || $filter->hasThermalFacet();
    }

    /** @return list<string> */
    private function uncheckedConditions(CoatingSystemsFilter $filter): array
    {
        $conditions = [];
        if ([] !== $filter->substrates) {
            $conditions[] = CoatingSystemsFilterAxis::Substrates->label();
        }
        if (null !== $filter->environment) {
            $conditions[] = CoatingSystemsFilterAxis::Environment->label();
        }
        if (null !== $filter->standard) {
            $conditions[] = CoatingSystemsFilterAxis::Standard->label();
        }
        if (null !== $filter->category) {
            $conditions[] = CoatingSystemsFilterAxis::Category->label();
        }
        if (null !== $filter->durability) {
            $conditions[] = CoatingSystemsFilterAxis::Durability->label();
        }
        if (null !== $filter->minApplicationTimeAt20) {
            $conditions[] = CoatingSystemsFilterAxis::MinApplicationTimeFromMinutes->label();
        }
        if (null !== $filter->hasDocuments) {
            $conditions[] = CoatingSystemsFilterAxis::HasDocuments->label();
        }
        if (null !== $filter->substanceTemperature && 0 === $filter->substanceIds->count()) {
            $conditions[] = CoatingSystemsFilterAxis::SubstanceTemperature->label();
        }
        if (!$filter->hasThermalFacet() && (null !== $filter->thermalTemperature || null !== $filter->thermalEnvironment)) {
            $conditions[] = CoatingSystemsFilterAxis::ThermalTemperature->label();
        }

        return $conditions;
    }
}
