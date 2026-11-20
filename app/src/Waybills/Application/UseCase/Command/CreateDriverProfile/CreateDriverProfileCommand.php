<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\CreateDriverProfile;

use App\Shared\Application\Command\Command;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;

final readonly class CreateDriverProfileCommand extends Command
{
    public function __construct(public DriverDetails $driverDetails, public RouteSettings $routeSettings)
    {
    }
}
