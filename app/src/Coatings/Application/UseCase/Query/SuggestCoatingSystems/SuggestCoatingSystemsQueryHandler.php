<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SuggestCoatingSystems;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemTitleDTO;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Лёгкий typeahead-поиск систем покрытий. Структурно — один-в-один с
 * SearchCoatingSystemsQueryHandler (листинг): findByFilter → items, НО строит лёгкие
 * CoatingSystemTitleDTO (без compliance/слоёв/документов). Единственная разница с
 * листингом — вес данных; ширина поиска (FTS + инфикс-ILIKE, Task 6) та же самая.
 */
final readonly class SuggestCoatingSystemsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private CoatingSystemRepositoryInterface $repo)
    {
    }

    public function __invoke(SuggestCoatingSystemsQuery $query): SuggestCoatingSystemsQueryResult
    {
        $page = $this->repo->findByFilter($query->filter);

        $items = array_map(
            static fn (CoatingSystem $system): CoatingSystemTitleDTO => new CoatingSystemTitleDTO(
                $system->getId(),
                $system->getTitle(),
            ),
            array_values($page->items),
        );

        return new SuggestCoatingSystemsQueryResult($items);
    }
}
