<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\AllCoatingsForSuggest;

use App\Coatings\Application\DTO\Coatings\CoatingSuggestDTO;
use App\Coatings\Application\DTO\Coatings\CoatingSuggestDTOTransformer;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Отдаёт весь каталог покрытий как лёгкие CoatingSuggestDTO. Проекция — общий с SearchCoatings
 * CoatingSuggestDTOTransformer. Без пейджера/фильтра: устройство кеширует список целиком.
 */
readonly class AllCoatingsForSuggestQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface $coatingRepository,
        private CoatingSuggestDTOTransformer $transformer,
    ) {
    }

    public function __invoke(AllCoatingsForSuggestQuery $query): AllCoatingsForSuggestQueryResult
    {
        $coatings = array_map(
            fn (Coating $coating): CoatingSuggestDTO => $this->transformer->fromEntity($coating),
            $this->coatingRepository->allForSuggest(),
        );

        return new AllCoatingsForSuggestQueryResult($coatings);
    }
}
