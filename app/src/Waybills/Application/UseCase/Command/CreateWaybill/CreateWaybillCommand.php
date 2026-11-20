<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\CreateWaybill;

use App\Shared\Application\Command\Command;
use App\Waybills\Application\DTO\RefuelingInputs;
use App\Waybills\Application\DTO\TripInput;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;

final readonly class CreateWaybillCommand extends Command
{
    /** @var list<TripInput> */
    public array $trips;

    /** @param list<WaybillPeriod> $absences */
    public function __construct(
        public string $vehicleId,
        public WaybillPeriod $period,
        public ?int $odometerEnd = null,
        public ?Fuel $fuel = null,
        public bool $routePresent = false,
        public ?int $expectedOdometerStart = null,
        public ?string $expectedFuelStart = null,
        public ?RefuelingInputs $refuelings = null,
        public array $absences = [],
        TripInput ...$trips,
    ) {
        $this->trips = $trips;
    }
}
