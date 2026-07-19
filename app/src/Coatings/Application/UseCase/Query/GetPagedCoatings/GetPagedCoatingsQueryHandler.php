<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetPagedCoatings;

use App\ChemicalResistance\Application\UseCase\Query\MatchSubstancesForSearch\MatchSubstancesForSearchQuery;
use App\ChemicalResistance\Application\UseCase\Query\MatchSubstancesForSearch\MatchSubstancesForSearchQueryHandler;
use App\Coatings\Application\DTO\Coatings\CoatingDTOTransformer;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

class GetPagedCoatingsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly CoatingRepositoryInterface        $coatingRepository,
        private readonly CoatingDTOTransformer             $coatingDTOTransformer,
        private readonly MatchSubstancesForSearchQueryHandler $matchSubstances,
    ) {
    }

    public function __invoke(GetPagedCoatingsQuery $query): GetPagedCoatingsQueryResult
    {
        $paginator = $this->coatingRepository->findByFilter($query->filter);
        $coatings = $this->coatingDTOTransformer->fromEntityList($paginator->items);

        if ($query->filter->search !== null && $coatings !== []) {
            $words = $query->filter->search->words();
            if ($words !== []) {
                $matches = ($this->matchSubstances)(new MatchSubstancesForSearchQuery(
                    coatingIds: array_map(fn($c) => $c->id, $coatings),
                    searchWords: $words,
                ));
                foreach ($coatings as $c) {
                    $c->matchedSubstances = $matches[$c->id] ?? [];
                }
            }
        }

        $pager = new Pager(
            $query->filter->pager->page,
            $query->filter->pager->perPage,
            $paginator->total
        );

        return new GetPagedCoatingsQueryResult($coatings, $pager);
    }
}
