<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatings;

use App\Coatings\Application\DTO\Coatings\CoatingSuggestDTO;
use App\Coatings\Application\DTO\Coatings\CoatingSuggestDTOTransformer;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

/**
 * Лёгкий постраничный поиск покрытий для typeahead. Структурно — один-в-один с
 * GetPagedCoatingsQueryHandler (findByFilter → items + Pager из фильтра), НО строит лёгкие
 * CoatingSuggestDTO (без тяжёлых связей: тегов/цветов/систем/химстойкости) и НЕ обогащает
 * подсветкой веществ. Единственная разница с GetPagedCoatings — вес данных.
 */
readonly class SearchCoatingsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface $coatingRepository,
        private CoatingSuggestDTOTransformer $transformer,
    ) {
    }

    public function __invoke(SearchCoatingsQuery $query): SearchCoatingsQueryResult
    {
        $paginator = $this->coatingRepository->findByFilter($query->filter);

        $coatings = array_map(
            fn (Coating $coating): CoatingSuggestDTO => $this->transformer->fromEntity($coating),
            array_values($paginator->items),
        );

        $pager = new Pager(
            $query->filter->pager->page,
            $query->filter->pager->perPage,
            $paginator->total,
        );

        return new SearchCoatingsQueryResult($coatings, $pager);
    }
}
