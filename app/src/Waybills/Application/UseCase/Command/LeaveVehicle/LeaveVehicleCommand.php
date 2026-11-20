<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\LeaveVehicle;

use App\Shared\Application\Command\Command;

final readonly class LeaveVehicleCommand extends Command
{
    public function __construct(public string $vehicleId)
    {
    }
}
