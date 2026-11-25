<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\FillWaybillRoute;

use App\Shared\Application\Command\Command;
use App\Waybills\Application\UseCase\Command\CreateWaybill\CreateWaybillCommand;
use App\Waybills\Application\UseCase\Command\UpdateWaybill\UpdateWaybillCommand;

final readonly class FillWaybillRouteCommand extends Command
{
    public function __construct(public CreateWaybillCommand|UpdateWaybillCommand $command)
    {
    }
}
