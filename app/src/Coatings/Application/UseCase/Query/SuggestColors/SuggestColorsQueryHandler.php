<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SuggestColors;

use App\Coatings\Application\DTO\Colors\ColorDTOTransformer;
use App\Coatings\Domain\Repository\ColorRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class SuggestColorsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ColorRepositoryInterface $repository,
        private ColorDTOTransformer $transformer,
    ) {
    }

    public function __invoke(SuggestColorsQuery $query): SuggestColorsQueryResult
    {
        $colors = $this->repository->suggest($query->query, $query->limit);

        return new SuggestColorsQueryResult($this->transformer->fromEntityList($colors));
    }
}
