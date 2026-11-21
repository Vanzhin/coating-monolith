<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetWaybillForEdit;

use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Waybills\Application\DTO\WaybillEditData;
use App\Waybills\Application\UseCase\Query\GetWaybill\GetWaybillQuery;
use App\Waybills\Domain\Repository\WaybillRepositoryInterface;

final readonly class GetWaybillForEditQueryHandler implements QueryHandlerInterface
{
    public function __construct(private QueryBusInterface $queries, private WaybillRepositoryInterface $waybills)
    {
    }

    public function __invoke(GetWaybillForEditQuery $query): WaybillEditData
    {
        $waybill = $this->queries->execute(new GetWaybillQuery($query->waybillId));

        return new WaybillEditData($waybill, $this->waybills->versionOf($waybill));
    }
}
