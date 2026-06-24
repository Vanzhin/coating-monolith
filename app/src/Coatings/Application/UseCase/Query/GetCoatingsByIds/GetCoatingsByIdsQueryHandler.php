<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingsByIds;

use App\Coatings\Application\DTO\Coatings\CoatingDTOTransformer;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

readonly class GetCoatingsByIdsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface $coatingRepository,
        private CoatingDTOTransformer      $coatingDTOTransformer,
    ) {
    }

    public function __invoke(GetCoatingsByIdsQuery $query): GetCoatingsByIdsQueryResult
    {
        $coatings = $this->coatingRepository->findByIds($query->ids);
        $dtos = array_map(
            fn($coating) => $this->coatingDTOTransformer->fromEntity($coating),
            $coatings,
        );
        return new GetCoatingsByIdsQueryResult($dtos);
    }
}
