<?php

declare(strict_types=1);

namespace App\Waybills\Application\DTO;

final readonly class VehicleListItem
{
    /** @param list<string> $fuelTypes */
    public function __construct(
        public string $id,
        public string $model,
        public string $registrationNumber,
        public array $fuelTypes,
        public int $odometer,
        public string $fuelRemaining,
        public bool $occupied,
        public bool $canLeave,
    ) {
    }
}
