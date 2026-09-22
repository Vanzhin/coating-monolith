<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingCalcContext;

use App\Coatings\Application\DTO\Coatings\CoatingSuggestDTOTransformer;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

readonly class GetCoatingCalcContextQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface $coatingRepository,
        private CoatingSuggestDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetCoatingCalcContextQuery $query): GetCoatingCalcContextQueryResult
    {
        $coating = $this->coatingRepository->findOneById($query->id);

        return new GetCoatingCalcContextQueryResult(
            null === $coating ? null : $this->transformer->fromEntity($coating),
        );
    }
}
