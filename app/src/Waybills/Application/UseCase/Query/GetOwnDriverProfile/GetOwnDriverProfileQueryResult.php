<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetOwnDriverProfile;

use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;

final readonly class GetOwnDriverProfileQueryResult
{
    public function __construct(
        public ?string $id,
        public ?DriverDetails $driverDetails,
        public ?RouteSettings $routeSettings,
    ) {
    }
}
