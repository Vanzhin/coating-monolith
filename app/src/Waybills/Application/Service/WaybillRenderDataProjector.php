<?php

declare(strict_types=1);

namespace App\Waybills\Application\Service;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TextValue;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use Brick\Math\BigDecimal;

final readonly class WaybillRenderDataProjector
{
    public function project(Waybill $waybill): RenderData
    {
        $driver = $waybill->driver();
        $fuel = $waybill->fuelOrNull();
        $refilled = $waybill->fuelRefilledOrNull();
        $consumed = null !== $refilled && null !== $fuel?->remainingOrNull() ? $waybill->fuelConsumed() : null;
        $byNorm = $waybill->consumptionByNorm();
        $routes = [];
        if ($waybill->hasRoute()) {
            foreach ($waybill->routeOdometers() as $entry) {
                $trip = $entry->routeLog;
                $routes[] = [
                    'date' => $trip->date->format('d.m.Y H:i'),
                    'odometer_start' => (string) $entry->start,
                    'odometer_end' => (string) $entry->end,
                    'from' => $trip->departure()->toString(),
                    'to' => $this->destination($trip->destination->location->toString(), $trip->destination->enterprise),
                    'distance' => (string) $trip->distanceInKilometers(),
                ];
            }
        }

        return new RenderData([
            'series' => new TextValue(''),
            'number' => new TextValue((string) $waybill->getNumber()),
            'day' => new TextValue($waybill->period()->from()->format('d')),
            'month' => new TextValue($waybill->period()->from()->format('m')),
            'year' => new TextValue($waybill->period()->from()->format('Y')),
            'period_start' => new TextValue($waybill->period()->from()->format('d.m.Y')),
            'period_end' => new TextValue($waybill->period()->to()->format('d.m.Y')),
            'vehicle_model' => new TextValue($waybill->vehicleModel()),
            'vehicle_type' => new TextValue(''),
            'registration_number' => new TextValue($waybill->vehicleRegistrationNumber()->formatted()),
            'driver_name' => new TextValue($driver->fullName ?? ''),
            'driver_short_name' => new TextValue(null === $driver ? '' : $this->shortName($driver->fullName)),
            'license_number' => new TextValue($driver?->licenseNumber->formatted() ?? ''),
            'license_date' => new TextValue($driver?->licenseIssuedAt->format('d.m.Y') ?? ''),
            'snils' => new TextValue($driver?->snils->formatted() ?? ''),
            'odometer_start' => new TextValue((string) $waybill->odometerStart()),
            'odometer_end' => new TextValue(null === $waybill->odometerEndOrNull() ? '' : (string) $waybill->odometerEnd()),
            'distance' => new TextValue((string) $waybill->distance()),
            'fuel_type' => new TextValue(implode(', ', array_map(static fn (FuelType $type): string => $type->value, $waybill->refueledFuelTypes()))),
            'fuel_refilled' => new TextValue(null === $refilled ? '' : (string) $refilled),
            'fuel_start' => new TextValue((string) $waybill->fuelStart()),
            'fuel_end' => new TextValue(null === $fuel?->remainingOrNull() ? '' : (string) $fuel->remaining()),
            'fuel_actual' => new TextValue(null === $consumed ? '' : (string) $consumed),
            'fuel_standard' => new TextValue(null === $byNorm ? '' : (string) $byNorm),
            'fuel_saved' => new TextValue($this->positiveDifference($byNorm, $consumed)),
            'fuel_excess' => new TextValue($this->positiveDifference($consumed, $byNorm)),
            'routes' => new RepeatValue($routes),
        ]);
    }

    private function shortName(string $fullName): string
    {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
        $name = array_shift($parts) ?? '';
        foreach ($parts as $part) {
            $name .= ' '.mb_strtoupper(mb_substr($part, 0, 1)).'.';
        }

        return $name;
    }

    private function destination(string $location, ?string $enterprise): string
    {
        return null === $enterprise || '' === trim($enterprise) ? $location : $location.', '.$enterprise;
    }

    private function positiveDifference(BigDecimal|float|null $first, BigDecimal|float|null $second): string
    {
        if (null === $first || null === $second) {
            return '';
        }
        $difference = BigDecimal::of((string) $first)->minus((string) $second);

        return $difference->isPositive() ? (string) $difference : '';
    }
}
