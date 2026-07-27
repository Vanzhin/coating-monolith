<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatings;

use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

/**
 * Лёгкий хендлер для поиска покрытий в typeahead.
 * Не загружает химстойкость, теги, производителей — только поля формы слоя.
 *
 * @return list<array{id: string, title: string, base: string, dftMin: int, dftMax: int}>
 */
readonly class SearchCoatingsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface $repository,
    ) {
    }

    public function __invoke(SearchCoatingsQuery $query): array
    {
        $search = SearchQuery::tryFromString($query->q);

        $result = $this->repository->findByFilter(new CoatingsFilter(
            search: $search,
            pager: Pager::fromPage(1, $query->limit),
        ));

        $items = [];
        foreach ($result->items as $coating) {
            $dft = $coating->getDftRange();
            $dftMin = (int) $dft->range->getMin();
            $dftMax = (int) $dft->range->getMax();
            $base = $coating->getBase()->value;
            $items[] = [
                'id' => $coating->getId(),
                'title' => sprintf('%s (%s, %d–%d мкм)', $coating->getTitle(), $base, $dftMin, $dftMax),
                'base' => $base,
                'dftMin' => $dftMin,
                'dftMax' => $dftMax,
            ];
        }

        return $items;
    }
}
