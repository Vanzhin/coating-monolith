<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\CreateVehicle;

use App\Shared\Application\Command\Command;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use Brick\Math\BigDecimal;

final readonly class CreateVehicleCommand extends Command
{
    /** @var list<FuelType> */
    public array $fuelTypes;

    public function __construct(
        public string $model,
        public string $registrationNumber,
        public \DateTimeImmutable $initialAt,
        public Location $initialLocation,
        public int $initialOdometer,
        public BigDecimal $initialFuelRemaining,
        public ?PositiveNumber $consumptionRate,
        public FuelType $defaultFuelType,
        FuelType ...$fuelTypes,
    ) {
        $this->fuelTypes = $fuelTypes;
    }
}
