<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\EnterVehicle;

use App\Shared\Application\Command\Command;

final readonly class EnterVehicleCommand extends Command
{
    public function __construct(public string $vehicleId)
    {
    }
}
