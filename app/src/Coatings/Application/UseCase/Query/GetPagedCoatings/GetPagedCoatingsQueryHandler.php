<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetPagedCoatings;

use App\Coatings\Application\DTO\Coatings\CoatingDTOTransformer;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

class GetPagedCoatingsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly CoatingRepositoryInterface $coatingRepository,
        private readonly CoatingDTOTransformer      $coatingDTOTransformer
    )
    {
    }

    public function __invoke(GetPagedCoatingsQuery $query): GetPagedCoatingsQueryResult
    {
        $paginator = $this->coatingRepository->findByFilter($query->filter);
        $coatings = $this->coatingDTOTransformer->fromEntityList($paginator->items);
        $pager = new Pager(
            $query->filter->pager->page,
            $query->filter->pager->perPage,
            $paginator->total
        );

        return new GetPagedCoatingsQueryResult($coatings, $pager);
    }
}
