<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Controller\Waybill;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Application\DTO\RefuelingInput;
use App\Waybills\Application\DTO\RefuelingInputs;
use App\Waybills\Application\DTO\TripInput;
use App\Waybills\Application\DTO\VehicleDTO;
use App\Waybills\Application\UseCase\Command\CreateWaybill\CreateWaybillCommand;
use App\Waybills\Application\UseCase\Command\UpdateWaybill\UpdateWaybillCommand;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use App\Waybills\Infrastructure\Mapper\LocationFormMapper;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Symfony\Component\Uid\Uuid;

final class WaybillFormMapper
{
    public function __construct(private readonly LocationFormMapper $locationMapper)
    {
    }

    /** @return array<string, mixed> */
    public function input(Waybill $waybill, int $version): array
    {
        $fuel = $waybill->fuelOrNull();
        $trips = $this->tripRows($waybill->hasRoute() ? $waybill->route() : new Route($waybill->vehicle()));

        return [
            'version' => (string) $version,
            'initialLocation' => $this->locationMapper->present($waybill->vehicle()->initialLocation()),
            'blankTrip' => $this->blankTrip(),
            'from' => $waybill->period()->from()->format('Y-m-d'),
            'to' => $waybill->period()->to()->format('Y-m-d'),
            'odometerEnd' => null === $waybill->odometerEndOrNull() ? '' : (string) $waybill->odometerEndOrNull(),
            'fuelType' => $fuel?->type() ?? $waybill->vehicle()->getDefaultFuelType()->value,
            'refuelings' => array_map(static fn ($operation): array => [
                'id' => (string) $operation->id,
                'date' => $operation->date->format('Y-m-d\TH:i:s'),
                'fuelType' => $operation->fuelType->value,
                'quantity' => (string) $operation->change,
            ], $waybill->refuelingOperations()->all()),
            'fuelRemaining' => null === $fuel?->remainingOrNull() ? '' : (string) $fuel->remaining(),
            'trips' => $trips,
            'absences' => array_map(static fn (WaybillPeriod $absence): array => [
                'from' => $absence->from()->format('Y-m-d\TH:i:s'),
                'to' => $absence->to()->format('Y-m-d\TH:i:s'),
            ], $waybill->absences()),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function withRoute(array $input, Route $route): array
    {
        $previousRows = [];
        foreach ($input['trips'] ?? [] as $row) {
            if (is_array($row) && isset($row['id']) && is_string($row['id'])) {
                $previousRows[$row['id']] = $row;
            }
        }
        $input['trips'] = $this->tripRows($route, $previousRows);

        return $input;
    }

    /**
     * @param array<string, array<string, mixed>> $previousRows
     *
     * @return list<array<string, string>>
     */
    private function tripRows(Route $route, array $previousRows = []): array
    {
        $rows = [];
        $pairId = null;
        foreach ($route->logs() as $trip) {
            $automatic = RouteLogOrigin::Automatic === $trip->origin;
            if ($automatic) {
                if (null === $pairId) {
                    $pairId = $trip->id;
                    $currentPairId = $pairId;
                } else {
                    $currentPairId = $pairId;
                    $pairId = null;
                }
            } else {
                $currentPairId = null;
                $pairId = null;
            }
            $location = $trip->destination->location;
            $previous = $previousRows[(string) $trip->id] ?? [];
            $rows[] = [
                'id' => (string) $trip->id,
                'date' => $trip->date->format('Y-m-d'),
                'locationId' => null === $location->id ? '' : (string) $location->id,
                'country' => $location->country,
                'city' => null === $location->city ? '' : $location->city->name,
                'cityType' => null === $location->city || null === $location->city->type ? '' : $location->city->type->value,
                'street' => $location->street,
                'house' => $location->house,
                'enterprise' => $trip->destination->enterprise ?? '',
                'latitude' => null === $location->latitude ? '' : (string) $location->latitude,
                'longitude' => null === $location->longitude ? '' : (string) $location->longitude,
                'distance' => (string) $trip->distanceInKilometers(),
                'origin' => $trip->origin->value,
                'pairId' => $automatic ? (string) $currentPairId : ($previous['pairId'] ?? ''),
                'originalDate' => $automatic ? $trip->date->format('Y-m-d') : ($previous['originalDate'] ?? ''),
                'originalDistance' => $automatic ? (string) $trip->distanceInKilometers() : ($previous['originalDistance'] ?? ''),
                'originalLocationId' => $automatic ? (null === $location->id ? '' : (string) $location->id) : ($previous['originalLocationId'] ?? ''),
                'originalCountry' => $automatic ? $location->country : ($previous['originalCountry'] ?? ''),
                'originalCity' => $automatic ? (null === $location->city ? '' : $location->city->name) : ($previous['originalCity'] ?? ''),
                'originalCityType' => $automatic ? (null === $location->city || null === $location->city->type ? '' : $location->city->type->value) : ($previous['originalCityType'] ?? ''),
                'originalStreet' => $automatic ? $location->street : ($previous['originalStreet'] ?? ''),
                'originalHouse' => $automatic ? $location->house : ($previous['originalHouse'] ?? ''),
                'originalEnterprise' => $automatic ? ($trip->destination->enterprise ?? '') : ($previous['originalEnterprise'] ?? ''),
                'originalLatitude' => $automatic ? (null === $location->latitude ? '' : (string) $location->latitude) : ($previous['originalLatitude'] ?? ''),
                'originalLongitude' => $automatic ? (null === $location->longitude ? '' : (string) $location->longitude) : ($previous['originalLongitude'] ?? ''),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function createInput(VehicleDTO $vehicle): array
    {
        if (null === $vehicle->odometer || null === $vehicle->fuelRemaining) {
            throw new AppException('Начальные показания автомобиля недоступны.');
        }

        return [
            'from' => '', 'to' => '',
            'initialLocation' => $this->locationMapper->present($vehicle->initialLocation),
            'blankTrip' => $this->blankTrip(),
            'initialOdometer' => (string) $vehicle->odometer,
            'initialFuel' => (string) $vehicle->fuelRemaining,
            'odometerEnd' => (string) $vehicle->odometer,
            'fuelType' => $vehicle->defaultFuelType,
            'refuelings' => [], 'fuelRemaining' => '', 'trips' => [], 'absences' => [],
        ];
    }

    /** @param array<string, mixed> $input */
    public function createCommand(string $vehicleId, array $input): CreateWaybillCommand
    {
        $period = WaybillPeriod::fromDates(
            $this->periodDate($this->text($input, 'from')),
            $this->periodDate($this->text($input, 'to'), endOfDay: true),
        );
        $initialOdometer = filter_var($this->text($input, 'initialOdometer'), FILTER_VALIDATE_INT);
        if (false === $initialOdometer) {
            throw new AppException('Начальное показание одометра не указано.');
        }
        $initialFuel = $this->text($input, 'initialFuel');
        if ('' === trim($initialFuel)) {
            throw new AppException('Начальный остаток топлива не указан.');
        }
        $trips = $this->trips($input);

        return new CreateWaybillCommand($vehicleId, $period, $this->odometerEnd($input), $this->fuel($initialFuel, null, $input),
            true, $initialOdometer, $initialFuel, $this->refuelings($input), $this->absences($input), ...$trips);
    }

    /** @param array<string, mixed> $input */
    public function updateCommand(Waybill $waybill, array $input): UpdateWaybillCommand
    {
        $period = WaybillPeriod::fromDates(
            $this->periodDate($this->text($input, 'from'), $waybill->period()->from()),
            $this->periodDate($this->text($input, 'to'), $waybill->period()->to(), endOfDay: true),
        );
        $trips = $this->trips($input, $waybill);
        $version = filter_var($this->text($input, 'version'), FILTER_VALIDATE_INT);
        if (false === $version || $version < 1) {
            throw new AppException('Версия путевого листа не указана. Обновите страницу.');
        }

        return new UpdateWaybillCommand($waybill->getId(), $version, $period, $this->odometerEnd($input), $this->fuel((string) $waybill->fuelStart(), $waybill->fuelOrNull(), $input),
            true, $this->absences($input, $waybill), $this->refuelings($input, $waybill), ...$trips);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<TripInput>
     */
    private function trips(array $input, ?Waybill $waybill = null): array
    {
        $known = [];
        foreach ($waybill?->trips() ?? [] as $trip) {
            $known[(string) $trip->id] = $trip;
        }
        $rows = $input['trips'] ?? [];
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new AppException('Некорректный список поездок.');
        }
        $trips = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new AppException('Некорректные данные поездки.');
            }
            $id = trim($this->text($row, 'id'));
            if ('' !== $id && !Uuid::isValid($id)) {
                throw new AppException('Некорректный идентификатор поездки.');
            }
            $distance = filter_var(trim($this->text($row, 'distance')), FILTER_VALIDATE_INT);
            if (false === $distance) {
                throw new AppException('Укажите целое расстояние поездки в километрах.');
            }
            $originValue = $this->text($row, 'origin');
            $origin = '' === $originValue ? RouteLogOrigin::Manual : RouteLogOrigin::tryFrom($originValue);
            if (null === $origin) {
                throw new AppException('Некорректное происхождение поездки.');
            }
            $pairId = trim($this->text($row, 'pairId'));
            if ('' !== $pairId && !Uuid::isValid($pairId)) {
                throw new AppException('Некорректный идентификатор автоматической пары.');
            }
            $originalDate = null;
            $originalDestination = null;
            if ('' !== $this->text($row, 'originalDate')) {
                $originalDistance = filter_var(trim($this->text($row, 'originalDistance')), FILTER_VALIDATE_INT);
                if (false === $originalDistance) {
                    throw new AppException('Укажите исходное расстояние автоматической поездки.');
                }
                $originalDate = $this->date($this->text($row, 'originalDate'));
                $originalDestination = new Destination($this->location($row, 'original'), $originalDistance);
            }
            $original = $known[$id] ?? null;
            $location = $this->location($row);
            if (null !== $original) {
                $location = $location->withCoordinatesFromUnchangedAddress($original->destination->location);
            }
            $trips[] = new TripInput(
                '' === $id ? null : Uuid::fromString($id),
                $this->date($this->text($row, 'date'), $original?->date),
                new Destination($location, $distance),
                $origin,
                '' === $pairId ? null : Uuid::fromString($pairId),
                $originalDate,
                $originalDestination,
            );
        }

        return $trips;
    }

    /** @param array<string, mixed> $row */
    private function location(array $row, string $prefix = ''): Location
    {
        $field = static fn (string $name): string => '' === $prefix ? lcfirst($name) : $prefix.$name;

        return $this->locationMapper->fromArray([
            'id' => $this->text($row, $field('LocationId')),
            'country' => $this->text($row, $field('Country')),
            'city' => $this->text($row, $field('City')),
            'cityType' => $this->text($row, $field('CityType')),
            'street' => $this->text($row, $field('Street')),
            'house' => $this->text($row, $field('House')),
            'enterprise' => $this->text($row, $field('Enterprise')),
            'latitude' => '' === $prefix ? null : $this->text($row, $field('Latitude')),
            'longitude' => '' === $prefix ? null : $this->text($row, $field('Longitude')),
        ]);
    }

    /** @return array<string, string> */
    public function blankTrip(): array
    {
        return [
            'id' => '', 'date' => '', 'locationId' => '', 'country' => Location::DEFAULT_COUNTRY, 'city' => '', 'cityType' => '', 'street' => '', 'house' => '', 'enterprise' => '',
            'latitude' => '', 'longitude' => '', 'distance' => '', 'origin' => RouteLogOrigin::Manual->value, 'pairId' => '',
            'originalDate' => '', 'originalDistance' => '', 'originalLocationId' => '', 'originalCountry' => '', 'originalCity' => '', 'originalCityType' => '', 'originalStreet' => '', 'originalHouse' => '', 'originalEnterprise' => '',
            'originalLatitude' => '', 'originalLongitude' => '',
        ];
    }

    /** @return array<string, string> */
    public function blankRefueling(): array
    {
        return ['id' => '', 'date' => '', 'fuelType' => '', 'quantity' => ''];
    }

    /** @param array<string, mixed> $input */
    private function refuelings(array $input, ?Waybill $waybill = null): RefuelingInputs
    {
        $known = [];
        foreach ($waybill?->refuelingOperations()->all() ?? [] as $operation) {
            $known[(string) $operation->id] = $operation;
        }
        $rows = $input['refuelings'] ?? [];
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new AppException('Некорректный список заправок.');
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new AppException('Некорректные данные заправки.');
            }
            $id = trim($this->text($row, 'id'));
            if ('' !== $id && !Uuid::isValid($id)) {
                throw new AppException('Некорректный идентификатор заправки.');
            }
            $type = FuelType::tryFrom($this->text($row, 'fuelType'));
            if (null === $type) {
                throw new AppException('Укажите вид топлива заправки.');
            }
            try {
                $quantity = BigDecimal::of(trim($this->text($row, 'quantity')));
            } catch (MathException $exception) {
                throw new AppException('Укажите корректное количество топлива.', previous: $exception);
            }
            $result[] = new RefuelingInput('' === $id ? null : Uuid::fromString($id), $this->date($this->text($row, 'date'), ($known[$id] ?? null)?->date), $type, $quantity);
        }

        return new RefuelingInputs(...$result);
    }

    /** @return array<string, string> */
    public function blankAbsence(): array
    {
        return ['from' => '', 'to' => ''];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<WaybillPeriod>
     */
    private function absences(array $input, ?Waybill $waybill = null): array
    {
        $rows = $input['absences'] ?? [];
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new AppException('Некорректный список периодов без поездок.');
        }
        $unchanged = [];
        foreach ($waybill?->absences() ?? [] as $absence) {
            $key = $absence->from()->format('Y-m-d\TH:i:s').'/'.$absence->to()->format('Y-m-d\TH:i:s');
            $unchanged[$key] = $absence;
        }
        $absences = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new AppException('Некорректные данные периода без поездок.');
            }
            $from = $this->text($row, 'from');
            $to = $this->text($row, 'to');
            $key = $this->dateWithSeconds($from).'/'.$this->dateWithSeconds($to);
            $original = $unchanged[$key] ?? null;
            $absences[] = WaybillPeriod::fromDates(
                $this->date($from, $original?->from()),
                $this->date($to, $original?->to()),
            );
        }

        return $absences;
    }

    /** @param array<string, mixed> $input */
    private function fuel(string $initial, ?Fuel $existing, array $input): ?Fuel
    {
        $remaining = trim($this->text($input, 'fuelRemaining'));
        $type = $this->text($input, 'fuelType');
        if ('' === $remaining && null === $existing) {
            return null;
        }
        $remainingValue = '' === $remaining ? null : $this->number($remaining);
        $refuelings = $existing?->refuelingsOrNull() ?? new Refuelings();

        return new Fuel($initial, $remainingValue, $refuelings, $type);
    }

    /** @param array<string, mixed> $input */
    private function odometerEnd(array $input): ?int
    {
        $odometer = trim($this->text($input, 'odometerEnd'));
        $value = '' === $odometer ? null : filter_var($odometer, FILTER_VALIDATE_INT);
        if (false === $value) {
            throw new AppException('Укажите целое конечное показание одометра.');
        }

        return $value;
    }

    private function periodDate(string $input, ?\DateTimeImmutable $existing = null, bool $endOfDay = false): \DateTimeImmutable
    {
        if (null !== $existing && $existing->format('Y-m-d') === $input) {
            return $existing;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $input);
        if (false === $date || $date->format('Y-m-d') !== $input) {
            throw new AppException('Укажите корректную дату периода.');
        }

        return $endOfDay ? $date->setTime(23, 59, 59, 999999) : $date;
    }

    private function dateWithSeconds(string $input): string
    {
        return 1 === preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/', $input) ? $input.':00' : $input;
    }

    private function date(string $input, ?\DateTimeImmutable $existing = null): \DateTimeImmutable
    {
        if (null !== $existing) {
            $sameDay = $existing->format('Y-m-d') === $input;
            $sameSecond = $existing->format('Y-m-d\TH:i:s') === $input;
            $sameMinute = '00' === $existing->format('s') && $existing->format('Y-m-d\TH:i') === $input;
            if ($sameDay || $sameSecond || $sameMinute) {
                return $existing;
            }
        }
        foreach (['Y-m-d', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $input);
            if (false !== $date && $date->format($format) === $input) {
                return $date;
            }
        }

        throw new AppException('Укажите корректные дату и время.');
    }

    private function number(string $input): float
    {
        try {
            $decimal = BigDecimal::of(trim($input));
        } catch (MathException $exception) {
            throw new AppException('Укажите корректное количество топлива.', previous: $exception);
        }
        $value = (float) (string) $decimal;
        if (!is_finite($value) || !$decimal->isEqualTo((string) $value)) {
            throw new AppException('Точность количества топлива не поддерживается без потери данных.');
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value)) {
            throw new AppException('Некорректные данные формы путевого листа.');
        }

        return $value;
    }
}
