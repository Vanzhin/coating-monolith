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
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Symfony\Component\Uid\Uuid;

final class WaybillFormMapper
{
    /** @return array<string, mixed> */
    public function input(Waybill $waybill, int $version): array
    {
        $fuel = $waybill->fuelOrNull();
        $trips = [];
        foreach ($waybill->trips() as $trip) {
            $location = $trip->destination->location;
            $trips[] = [
                'id' => (string) $trip->id,
                'date' => $trip->date->format('Y-m-d\TH:i:s'),
                'country' => $location->country, 'city' => $location->city,
                'street' => $location->street, 'house' => $location->house,
                'enterprise' => $trip->destination->enterprise ?? '',
                'distance' => (string) $trip->distanceInKilometers(),
            ];
        }

        return [
            'version' => (string) $version,
            'initialLocation' => $waybill->vehicle()->initialLocation()->jsonSerialize(),
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

    /** @return array<string, mixed> */
    public function createInput(VehicleDTO $vehicle): array
    {
        if (null === $vehicle->odometer || null === $vehicle->fuelRemaining) {
            throw new AppException('Начальные показания автомобиля недоступны.');
        }

        return [
            'from' => '', 'to' => '',
            'initialLocation' => $vehicle->initialLocation->jsonSerialize(),
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
            $original = $known[$id] ?? null;
            $trips[] = new TripInput(
                '' === $id ? null : Uuid::fromString($id),
                $this->date($this->text($row, 'date'), $original?->date),
                new Destination(new Location($this->text($row, 'country'), $this->text($row, 'city'), $this->text($row, 'street'), $this->text($row, 'house')), $distance, $this->text($row, 'enterprise')),
            );
        }

        return $trips;
    }

    /** @return array<string, string> */
    public function blankTrip(): array
    {
        return ['id' => '', 'date' => '', 'country' => Location::DEFAULT_COUNTRY, 'city' => '', 'street' => '', 'house' => '', 'enterprise' => '', 'distance' => ''];
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
            $sameSecond = $existing->format('Y-m-d\TH:i:s') === $input;
            $sameMinute = '00' === $existing->format('s') && $existing->format('Y-m-d\TH:i') === $input;
            if ($sameSecond || $sameMinute) {
                return $existing;
            }
        }
        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $format) {
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
