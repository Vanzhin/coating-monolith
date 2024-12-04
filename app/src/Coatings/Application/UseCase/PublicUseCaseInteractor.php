<?php
declare(strict_types=1);


namespace App\Coatings\Application\UseCase;

use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQuery;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Shared\Application\Query\QueryBusInterface;


readonly class PublicUseCaseInteractor
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    public function getPagedCoatings(CoatingsFilter $filter): GetPagedCoatingsQueryResult
    {
        $query = new GetPagedCoatingsQuery($filter);

        return $this->queryBus->execute($query);
    }
}