<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\UpdateDriverProfile;

use App\Shared\Application\Command\Command;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;
use Symfony\Component\Uid\Uuid;

final readonly class UpdateDriverProfileCommand extends Command
{
    public function __construct(public Uuid $id, public DriverDetails $driverDetails, public RouteSettings $routeSettings)
    {
    }
}
