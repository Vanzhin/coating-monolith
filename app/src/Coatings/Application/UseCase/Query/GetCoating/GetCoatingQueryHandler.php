<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoating;

use App\Coatings\Application\DTO\Coatings\CoatingDTOTransformer;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

readonly class GetCoatingQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface $coatingRepository,
        private CoatingDTOTransformer      $coatingDTOTransformer
    )
    {
    }

    public function __invoke(GetCoatingQuery $query): GetCoatingQueryResult
    {
        $manufacturer = $this->coatingRepository->findOneById($query->coatingId);
        if (null === $manufacturer) {
            return new GetCoatingQueryResult(null);
        }

        return new GetCoatingQueryResult($this->coatingDTOTransformer->fromEntity($manufacturer));
    }
}
