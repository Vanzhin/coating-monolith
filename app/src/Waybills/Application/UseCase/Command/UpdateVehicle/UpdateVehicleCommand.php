<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\UpdateVehicle;

use App\Shared\Application\Command\Command;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;

final readonly class UpdateVehicleCommand extends Command
{
    /** @var list<FuelType> */
    public array $fuelTypes;

    public function __construct(
        public string $id,
        public string $model,
        public string $registrationNumber,
        public ?PositiveNumber $consumptionRate,
        public FuelType $defaultFuelType,
        FuelType ...$fuelTypes,
    ) {
        $this->fuelTypes = $fuelTypes;
    }
}
