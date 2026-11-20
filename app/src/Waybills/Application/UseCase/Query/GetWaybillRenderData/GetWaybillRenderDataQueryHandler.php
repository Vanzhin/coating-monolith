<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetWaybillRenderData;

use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Templating\RenderData;
use App\Waybills\Application\Service\WaybillRenderDataProjector;
use App\Waybills\Application\UseCase\Query\GetWaybill\GetWaybillQuery;

final readonly class GetWaybillRenderDataQueryHandler implements QueryHandlerInterface
{
    public function __construct(private QueryBusInterface $queries, private WaybillRenderDataProjector $projector)
    {
    }

    public function __invoke(GetWaybillRenderDataQuery $query): RenderData
    {
        return $this->projector->project($this->queries->execute(new GetWaybillQuery($query->waybillId)));
    }
}
