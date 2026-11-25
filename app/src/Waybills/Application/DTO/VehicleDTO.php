<?php

declare(strict_types=1);

namespace App\Waybills\Application\DTO;

use App\Waybills\Domain\Aggregate\Route\Location;

final readonly class VehicleDTO
{
    /** @param list<string> $fuelTypes */
    public function __construct(
        public string $id,
        public string $model,
        public string $registrationNumber,
        public array $fuelTypes,
        public int|float|null $consumptionRate,
        public ?int $odometer,
        public int|float|string|null $fuelRemaining,
        public Location $initialLocation,
        public string $defaultFuelType = '',
        public bool $occupied = false,
        public ?string $driverName = null,
        public ?Location $routeStartLocation = null,
    ) {
    }
}
