<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetPagedWaybills;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\PaginationResult;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\Service\AccessControl\WaybillAccessControl;
use App\Waybills\Domain\Repository\WaybillFilter;
use App\Waybills\Domain\Repository\WaybillRepositoryInterface;

final readonly class GetPagedWaybillsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private WaybillRepositoryInterface $waybills, private WaybillAccessControl $access)
    {
    }

    public function __invoke(GetPagedWaybillsQuery $query): PaginationResult
    {
        if (!$this->access->canCreate() && !$this->access->isManager()) {
            throw new ForbiddenException();
        }
        $filter = $query->filter;
        $filter = new WaybillFilter($this->access->currentUserId(), $filter->vehicleId, $filter->pager);

        return $this->waybills->findByFilter($filter);
    }
}
