<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\UpdateWaybill;

use App\Shared\Application\Command\Command;
use App\Waybills\Application\DTO\RefuelingInputs;
use App\Waybills\Application\DTO\TripInput;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;

final readonly class UpdateWaybillCommand extends Command
{
    /** @var list<TripInput> */
    public array $trips;

    /** @param list<WaybillPeriod> $absences */
    public function __construct(
        public string $waybillId,
        public int $expectedVersion,
        public WaybillPeriod $period,
        public ?int $odometerEnd,
        public ?Fuel $fuel,
        public bool $routePresent,
        public array $absences = [],
        public ?RefuelingInputs $refuelings = null,
        TripInput ...$trips,
    ) {
        $this->trips = $trips;
    }
}
