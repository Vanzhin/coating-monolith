<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetPagedLocations;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\PaginationResult;
use App\Waybills\Application\Service\AccessControl\LocationAccessControl;
use App\Waybills\Domain\Repository\LocationFilter;
use App\Waybills\Domain\Repository\LocationRepositoryInterface;

final readonly class GetPagedLocationsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private LocationRepositoryInterface $locations, private LocationAccessControl $access)
    {
    }

    public function __invoke(GetPagedLocationsQuery $query): PaginationResult
    {
        $filter = $query->filter;

        return $this->locations->findByFilter(new LocationFilter($this->access->currentUserId(), $filter->search, $filter->pager));
    }
}
