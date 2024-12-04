<?php
declare(strict_types=1);


namespace App\Coatings\Infrastructure\Api;

use App\Coatings\Application\UseCase\PublicUseCaseInteractor;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Proposals\Infrastructure\Adapter\CoatingsApiInterface;
use App\Shared\Domain\Repository\Pager;

class CoatingsApi implements CoatingsApiInterface
{
    public function __construct(
        private PublicUseCaseInteractor $queryInteractor,
    )
    {
    }

    public function getPagedCoatings(): GetPagedCoatingsQueryResult
    {
        return $this->queryInteractor->getPagedCoatings(new CoatingsFilter(null, Pager::fromPage(1, 1000)));
    }

}