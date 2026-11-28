<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Waybill;

use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Vehicle\FuelOperation;
use App\Waybills\Domain\Aggregate\Vehicle\FuelOperationType;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Vehicle\RefuelingOperations;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Symfony\Component\Uid\Uuid;

class Waybill extends Aggregate
{
    private const float CONSUMPTION_DEVIATION = 0.15;
    private const float CONSUMPTION_COMPARISON_TOLERANCE = 0.000000001;

    private bool $locked = false;
    private WaybillPeriod $period;
    private ?int $odometerEnd = null;
    private ?Fuel $fuel = null;
    private ?Route $route = null;
    private bool $routePresent = false;
    private ?RefuelingOperations $refuelingOperations = null;
    /** @var list<WaybillPeriod> */
    private array $absences = [];
    private ?DriverDetails $approvedDriver = null;
    private ?Location $approvedBase = null;
    private ?string $approvedVehicleModel = null;
    private ?RegistrationNumber $approvedRegistrationNumber = null;
    private ?PositiveNumber $approvedConsumptionRate = null;

    private function __construct(
        private readonly Uuid $id,
        private readonly int $number,
        private readonly Vehicle $vehicle,
        private readonly int $odometerStart,
        private readonly BigDecimal $fuelStart,
        WaybillPeriod $period,
    ) {
        if ($number < 1) {
            throw new AppException('Номер путевого листа должен быть положительным.');
        }
        if ($odometerStart < 0) {
            throw new AppException('Начальное показание одометра должно быть неотрицательным.');
        }
        if ($fuelStart->isNegative()) {
            throw new AppException('Начальный остаток топлива не может быть отрицательным.');
        }
        $this->period = $period;
        $this->odometerEnd = $odometerStart;
        $this->refuelingOperations = new RefuelingOperations();
    }

    public static function createDraft(
        Uuid $id,
        int $number,
        Vehicle $vehicle,
        DateTimeInterval $period,
    ): self {
        if (null === $vehicle->driver()) {
            throw new AppException('Нельзя создать путевой лист без водителя автомобиля.');
        }

        return new self(
            $id,
            $number,
            $vehicle,
            $vehicle->currentOdometer(),
            $vehicle->fuelRemaining(),
            new WaybillPeriod($period),
        );
    }

    public static function create(
        Uuid $id,
        int $number,
        Vehicle $vehicle,
        WaybillPeriod $period,
        int $odometerEnd,
        Fuel $fuel,
        Route $route,
        WaybillPeriod ...$absences,
    ): self {
        $waybill = new self($id, $number, $vehicle, $vehicle->currentOdometer(), $vehicle->fuelRemaining(), $period);
        $waybill->revise($period, $odometerEnd, $fuel, $route, ...$absences);

        return $waybill;
    }

    public static function restore(
        Uuid $id,
        int $number,
        Vehicle $vehicle,
        int $odometerStart,
        BigDecimal $fuelStart,
        WaybillPeriod $period,
        int $odometerEnd,
        Fuel $fuel,
        Route $route,
        WaybillPeriod ...$absences,
    ): self {
        $waybill = new self($id, $number, $vehicle, $odometerStart, $fuelStart, $period);
        $waybill->revise($period, $odometerEnd, $fuel, $route, ...$absences);

        return $waybill;
    }

    public function revise(
        WaybillPeriod $period,
        int $odometerEnd,
        Fuel $fuel,
        Route $route,
        WaybillPeriod ...$absences,
    ): void {
        $this->assertEditable();
        $this->assertTripsLoaded();
        $trips = iterator_to_array($route->logs(), false);
        $distance = $this->assertTripsConsistent($period, $odometerEnd, ...$trips);
        $this->assertCompleteTripDistance($odometerEnd, $distance);
        $this->reviseDraft($period, $odometerEnd, $fuel, $route, ...$absences);
    }

    public function reviseDraft(
        WaybillPeriod $period,
        ?int $odometerEnd,
        ?Fuel $fuel,
        ?Route $route,
        WaybillPeriod ...$absences,
    ): void {
        $this->reviseDraftWithRefuelingOperations($period, $odometerEnd, $fuel, $route, $this->refuelingOperations(), ...$absences);
    }

    public function reviseDraftWithRefuelingOperations(
        WaybillPeriod $period,
        ?int $odometerEnd,
        ?Fuel $fuel,
        ?Route $route,
        RefuelingOperations $refuelings,
        WaybillPeriod ...$absences,
    ): void {
        $this->assertEditable();
        $this->assertTripsLoaded();
        $this->refuelingOperations();
        $this->assertAbsences($period, ...$absences);
        $this->assertLegacyRefuelingsPreserved($fuel);
        if (null !== $fuel) {
            $this->supportedFuelType($fuel);
            $this->assertFuelStart($fuel);
            $this->assertRefuelingPeriods($period, $fuel, ...$absences);
        }
        if (null !== $route && !$this->isForVehicle($route->vehicle())) {
            throw new AppException('Маршрут относится к другому автомобилю.');
        }
        $trips = null === $route ? [] : iterator_to_array($route->logs(), false);
        $distance = $this->assertTripsConsistent($period, $odometerEnd, ...$trips);
        $odometerEnd ??= $this->odometerStart + $distance;
        $this->assertCompleteTripDistance($odometerEnd, $distance);
        $this->assertUnchangedTripsKeepTheirDeparture(...$trips);
        foreach ($trips as $trip) {
            $this->assertTripOutsideAbsences($trip, ...$absences);
        }
        if (null !== $fuel && null !== $route) {
            $this->assertRefuelingPeriods($period, $fuel, ...$absences);
        }
        $this->assertRefuelingOperations($period, $fuel, $refuelings, ...$absences);
        if (null === $fuel) {
            throw new AppException('Укажите остаток топлива на конец периода.');
        }
        $fuel->remaining();

        $this->period = $period;
        $this->odometerEnd = $odometerEnd;
        $this->fuel = $fuel;
        $this->route = $route;
        $this->routePresent = null !== $route;
        $this->absences = $absences;
        $this->refuelingOperations = $refuelings;
    }

    public function approve(Uuid $id, Vehicle $vehicle, FuelOperation ...$refuelings): FuelOperation
    {
        $fuel = $this->fuel();
        $reconciliation = $this->createFuelReconciliation(
            $id,
            $vehicle,
            $this->period->to(),
            $this->supportedFuelType($fuel),
            BigDecimal::of((string) $fuel->remaining()),
            ...$refuelings,
        );
        $this->lock();

        return $reconciliation;
    }

    public function lock(): void
    {
        $this->assertEditable();
        if (null === $this->odometerEnd || null === $this->fuel || null === $this->route) {
            throw new AppException('Заполните конечное показание одометра, топливо и маршрут перед утверждением.');
        }
        $driver = $this->vehicle->driver() ?? throw new AppException('Нельзя утвердить путевой лист без водителя автомобиля.');
        $this->fuel->remaining();
        if ([] === $this->refuelingOperations()->all()) {
            $this->fuel->refuelings();
        }
        $this->supportedFuelType($this->fuel);
        $this->assertTripsComplete(...iterator_to_array($this->route->logs(), false));
        $this->assertRefuelingPeriods($this->period, $this->fuel, ...$this->absences);
        $this->assertRefuelingOperations($this->period, $this->fuel, $this->refuelingOperations(), ...$this->absences);

        $this->approvedDriver = $driver->getDriverDetails();
        $this->approvedBase = $driver->getBase();
        $this->approvedVehicleModel = $this->vehicle->getModel();
        $this->approvedRegistrationNumber = $this->vehicle->getRegistrationNumber();
        $this->approvedConsumptionRate = $this->vehicle->getConsumptionRate();
        $this->locked = true;
    }

    public function assertEditable(): void
    {
        if ($this->locked) {
            throw new AppException('Зафиксированный путевой лист нельзя изменять или удалять.');
        }
    }

    public function assertLocked(): void
    {
        if (!$this->locked) {
            throw new AppException('Путевой лист ещё не зафиксирован.');
        }
    }

    public function assertVehicleHasNoOtherDraft(bool $hasOtherDraft): void
    {
        if ($hasOtherDraft) {
            throw new AppException('Для этого автомобиля уже есть черновик путевого листа. Сначала завершите и утвердите его.');
        }
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function vehicleId(): string
    {
        return $this->vehicle->getId();
    }

    public function assertCanSave(Vehicle $vehicle): void
    {
        if (!$this->isForVehicle($vehicle)) {
            throw new AppException('Путевой лист относится к другому автомобилю.');
        }
        if (null === $vehicle->driver()) {
            throw new AppException('Нельзя сохранить путевой лист без водителя автомобиля.');
        }
    }

    public function assertInitialReadingsMatch(Vehicle $vehicle): void
    {
        $this->assertCanSave($vehicle);
        if ($this->odometerStart !== $vehicle->currentOdometer() || !$this->fuelStart->isEqualTo($vehicle->fuelRemaining())) {
            throw new AppException('Показания автомобиля изменились. Создайте путевой лист заново.');
        }
    }

    public function assertInitialReadingsMatchEntered(?int $odometer, ?string $fuel): void
    {
        try {
            $fuelMatches = null === $fuel || $this->fuelStart->isEqualTo($fuel);
        } catch (MathException $exception) {
            throw new AppException('Некорректный начальный остаток топлива.', previous: $exception);
        }
        if ((null !== $odometer && $this->odometerStart !== $odometer) || !$fuelMatches) {
            throw new AppException('Показания автомобиля изменились. Обновите страницу и проверьте введённые данные.');
        }
    }

    public function isForVehicle(Vehicle $vehicle): bool
    {
        return $this->vehicle->getId() === $vehicle->getId();
    }

    public function assertRefuelingOperations(WaybillPeriod $period, ?Fuel $fuel, RefuelingOperations $refuelings, WaybillPeriod ...$absences): void
    {
        $operations = $refuelings->all();
        $hasLegacyRefuelings = $this->hasLegacyRefuelings($fuel) || $this->hasLegacyRefuelings($this->fuel);
        if ([] !== $operations && $hasLegacyRefuelings) {
            throw new AppException('Нельзя добавить отдельные заправки к ранее сохранённой общей сумме.');
        }
        foreach ($operations as $operation) {
            if (!$operation->belongsTo($this->vehicle)) {
                throw new AppException('Заправка относится к другому автомобилю.');
            }
            if (!$period->contains($operation->date)) {
                throw new AppException('Дата заправки находится вне периода путевого листа.');
            }
            foreach ($absences as $absence) {
                if ($absence->contains($operation->date)) {
                    throw new AppException('Заправка попадает в период без поездок.');
                }
            }
        }
    }

    public function refuelingOperations(): RefuelingOperations
    {
        return $this->refuelingOperations ?? throw new \LogicException('Заправки путевого листа ещё не загружены.');
    }

    /** @return list<FuelType> */
    public function refueledFuelTypes(): array
    {
        return $this->refuelingOperations()->fuelTypes();
    }

    public function restoreRefuelingOperations(RefuelingOperations $refuelings): void
    {
        if (null !== $this->refuelingOperations) {
            throw new \LogicException('Заправки путевого листа уже загружены.');
        }
        $this->assertTripsLoaded();
        $this->assertRefuelingOperations($this->period, $this->fuel, $refuelings, ...$this->absences);
        $this->refuelingOperations = $refuelings;
    }

    public function assertSavedRefuelingsAreValid(): void
    {
        $this->assertTripsLoaded();
        $this->assertRefuelingOperations($this->period, $this->fuel, $this->refuelingOperations(), ...$this->absences);
    }

    public function assertTrips(int $odometerEnd, RouteLog ...$trips): void
    {
        $distance = $this->assertTripsConsistent($this->period, $odometerEnd, ...$trips);
        $this->assertCompleteTripDistance($odometerEnd, $distance);
        foreach ($trips as $trip) {
            $this->assertTripOutsideAbsences($trip, ...$this->absences);
        }
    }

    public function assertTripsComplete(RouteLog ...$trips): void
    {
        $odometerEnd = $this->odometerEnd();
        $distance = $this->assertTripsConsistent($this->period, $odometerEnd, ...$trips);
        foreach ($trips as $trip) {
            $this->assertTripOutsideAbsences($trip, ...$this->absences);
        }
        $this->assertCompleteTripDistance($odometerEnd, $distance);
    }

    public function assertOdometerMatchesTrips(): void
    {
        $this->assertTrips($this->odometerEnd(), ...$this->trips());
    }

    public function setTrips(RouteLog ...$trips): void
    {
        $this->assertEditable();
        $this->assertTripsLoaded();
        $distance = $this->assertTripsConsistent($this->period, null, ...$trips);
        $this->assertUnchangedTripsKeepTheirDeparture(...$trips);
        foreach ($trips as $trip) {
            $this->assertTripOutsideAbsences($trip, ...$this->absences);
        }
        $last = [] === $trips ? null : $trips[array_key_last($trips)];
        $route = new Route($this->vehicle, $trips[0] ?? null, $last);
        if (null !== $this->fuel) {
            $this->assertRefuelingPeriods($this->period, $this->fuel, ...$this->absences);
        }
        $this->assertRefuelingOperations($this->period, $this->fuel, $this->refuelingOperations(), ...$this->absences);

        $this->route = $route;
        $this->routePresent = true;
        $this->odometerEnd = $this->odometerStart + $distance;
    }

    public function restoreTrips(RouteLog ...$trips): void
    {
        if (!$this->routePresent) {
            if ([] !== $trips) {
                throw new \LogicException('Для листа без маршрута найдены поездки.');
            }

            return;
        }
        if (null !== $this->route) {
            throw new \LogicException('Маршрут путевого листа уже загружен.');
        }

        $route = Route::fromUnorderedTrips($this->vehicle, ...$trips);
        $trips = iterator_to_array($route->logs(), false);
        $distance = $this->assertTripsConsistent($this->period, $this->odometerEnd, ...$trips);
        foreach ($trips as $trip) {
            $this->assertTripOutsideAbsences($trip, ...$this->absences);
        }
        if ($this->locked) {
            $this->assertCompleteTripDistance($this->odometerEnd(), $distance);
        }
        if (null !== $this->fuel) {
            $this->assertRefuelingPeriods($this->period, $this->fuel, ...$this->absences);
        }
        $this->route = $route;
    }

    public function hasRoute(): bool
    {
        return $this->routePresent;
    }

    /** @return list<RouteLog> */
    public function trips(): array
    {
        return $this->routePresent ? iterator_to_array($this->route()->logs(), false) : [];
    }

    public function setOdometerEnd(int $odometerEnd): void
    {
        $this->assertEditable();
        $this->assertTrips($odometerEnd, ...$this->trips());
        $this->odometerEnd = $odometerEnd;
    }

    public function createFuelReconciliation(
        Uuid $id,
        Vehicle $vehicle,
        \DateTimeImmutable $date,
        FuelType $fuelType,
        BigDecimal $actualRemaining,
        FuelOperation ...$refuelings,
    ): FuelOperation {
        $this->assertEditable();
        if (!$this->isForVehicle($vehicle)) {
            throw new AppException('Путевой лист относится к другому автомобилю.');
        }
        if ($date < $this->period->from()) {
            throw new AppException('Сверка топлива не может быть раньше начала периода путевого листа.');
        }

        $calculatedRemaining = $this->fuelStart;
        $legacy = $this->fuel?->refuelingsOrNull();
        if ([] === $refuelings && null !== $legacy) {
            $calculatedRemaining = $calculatedRemaining->plus((string) $legacy->total());
        }
        $seen = [];
        foreach ($refuelings as $refueling) {
            if (!$refueling->belongsTo($vehicle)) {
                throw new AppException('Заправка относится к другому автомобилю.');
            }
            if (FuelOperationType::Refueling !== $refueling->type) {
                throw new AppException('Для расчёта остатка укажите только заправки.');
            }
            if (!$this->period->contains($refueling->date)) {
                throw new AppException('Дата заправки находится вне периода путевого листа.');
            }
            if ($refueling->date > $date) {
                throw new AppException('Заправка не может быть позже сверки топлива.');
            }
            $operationId = (string) $refueling->id;
            if (isset($seen[$operationId])) {
                throw new AppException('Одна заправка не может учитываться дважды.');
            }
            $seen[$operationId] = true;
            $calculatedRemaining = $calculatedRemaining->plus($refueling->change);
        }

        return FuelOperation::reconcile($id, $vehicle, $date, $fuelType, $calculatedRemaining, $actualRemaining, $this);
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function driver(): ?DriverDetails
    {
        return $this->locked ? $this->approvedDriver : $this->vehicle->driver()?->getDriverDetails();
    }

    public function vehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function base(): ?Location
    {
        return $this->locked ? $this->approvedBase : $this->vehicle->driver()?->getBase();
    }

    public function vehicleModel(): string
    {
        return $this->locked ? ($this->approvedVehicleModel ?? throw new \LogicException('Не зафиксирована модель автомобиля.')) : $this->vehicle->getModel();
    }

    public function vehicleRegistrationNumber(): RegistrationNumber
    {
        return $this->locked ? ($this->approvedRegistrationNumber ?? throw new \LogicException('Не зафиксирован госномер автомобиля.')) : $this->vehicle->getRegistrationNumber();
    }

    /** @return list<FuelType> */
    public function vehicleFuelTypes(): array
    {
        return $this->vehicle->getFuelTypes();
    }

    public function vehicleConsumptionRate(): ?PositiveNumber
    {
        return $this->locked ? $this->approvedConsumptionRate : $this->vehicle->getConsumptionRate();
    }

    public function period(): WaybillPeriod
    {
        return $this->period;
    }

    public function odometerStart(): int
    {
        return $this->odometerStart;
    }

    public function odometerEnd(): int
    {
        if (null === $this->odometerEnd) {
            throw new AppException('Укажите конечное показание одометра.');
        }

        return $this->odometerEnd;
    }

    public function odometerEndOrNull(): ?int
    {
        return $this->odometerEnd;
    }

    public function fuelStart(): BigDecimal
    {
        return $this->fuelStart;
    }

    public function distance(): int
    {
        return $this->odometerEnd() - $this->odometerStart();
    }

    public function tripDistance(): int
    {
        return $this->routePresent ? $this->route()->distance() : 0;
    }

    public function fuel(): Fuel
    {
        if (null === $this->fuel) {
            throw new AppException('Заполните данные о топливе.');
        }

        return $this->fuel;
    }

    public function fuelOrNull(): ?Fuel
    {
        return $this->fuel;
    }

    public function route(): Route
    {
        if (null === $this->route) {
            $this->assertTripsLoaded();
            throw new AppException('Заполните маршрут.');
        }

        return $this->route;
    }

    private function assertTripsLoaded(): void
    {
        if ($this->routePresent && null === $this->route) {
            throw new \LogicException('Поездки путевого листа ещё не загружены.');
        }
    }

    /** @return list<OdometerLog> */
    public function routeOdometers(): array
    {
        $result = [];
        $start = $this->odometerStart();
        foreach ($this->route()->logs() as $log) {
            $end = $start + $log->distanceInKilometers();
            $result[] = new OdometerLog($log, $start, $end);
            $start = $end;
        }

        return $result;
    }

    /** @return list<WaybillPeriod> */
    public function absences(): array
    {
        return $this->absences;
    }

    public function hasConsumptionDeviation(): bool
    {
        $norm = $this->vehicleConsumptionRate()?->value();
        if (null === $norm) {
            return false;
        }
        $actual = $this->actualConsumptionPer100Km();
        if (null === $actual) {
            return false;
        }

        return abs($actual - $norm) / $norm > self::CONSUMPTION_DEVIATION + self::CONSUMPTION_COMPARISON_TOLERANCE;
    }

    public function fuelRefilledOrNull(): ?BigDecimal
    {
        $operations = $this->refuelingOperations();
        if ([] !== $operations->all()) {
            return $operations->total();
        }
        $legacy = $this->fuel?->refuelingsOrNull();

        return null === $legacy ? null : BigDecimal::of((string) $legacy->total());
    }

    public function fuelConsumed(): BigDecimal
    {
        $fuel = $this->fuel();
        $refueled = $this->fuelRefilledOrNull() ?? throw new AppException('Укажите количество заправленного топлива.');

        return $this->fuelStart->plus($refueled)->minus((string) $fuel->remaining());
    }

    public function actualConsumptionPer100Km(): ?float
    {
        if (0 == $this->distance()) {
            return null;
        }
        $actual = $this->fuelConsumed()->toFloat() / $this->distance() * 100;
        if (!is_finite($actual)) {
            throw new AppException('Фактический расход топлива слишком велик для расчёта.');
        }

        return $actual;
    }

    public function consumptionByNorm(): ?float
    {
        $norm = $this->vehicleConsumptionRate()?->value();
        if (null === $norm) {
            return null;
        }
        $consumption = $this->distance() / 100 * $norm;
        if (!is_finite($consumption)) {
            throw new AppException('Расход топлива по норме слишком велик для расчёта.');
        }

        return $consumption;
    }

    private function supportedFuelType(Fuel $fuel): FuelType
    {
        $fuelType = FuelType::tryFrom($fuel->type());
        if (null === $fuelType || !$this->vehicle->supportsFuelType($fuelType)) {
            throw new AppException('Выбранная марка топлива не указана для автомобиля.');
        }

        return $fuelType;
    }

    private function hasLegacyRefuelings(?Fuel $fuel): bool
    {
        return ($fuel?->refuelingsOrNull()?->total() ?? 0) > 0;
    }

    private function assertLegacyRefuelingsPreserved(?Fuel $fuel): void
    {
        $existing = $this->fuel?->refuelingsOrNull();
        if (null === $existing || 0 === $existing->total()) {
            return;
        }
        $replacement = $fuel?->refuelingsOrNull();
        if (null === $replacement || !$existing->equals($replacement)) {
            throw new AppException('Ранее сохранённые заправки нельзя удалить или изменить до их переноса в журнал автомобиля.');
        }
    }

    private function assertFuelStart(Fuel $fuel): void
    {
        $initialFuel = BigDecimal::of((string) $fuel->initial());
        if (!$this->fuelStart->isEqualTo($initialFuel)) {
            throw new AppException('Начальный остаток топлива должен совпадать с сохранённым в путевом листе.');
        }
    }

    private function assertTripsConsistent(WaybillPeriod $period, ?int $odometerEnd, RouteLog ...$trips): int
    {
        if (null !== $odometerEnd && $odometerEnd < $this->odometerStart) {
            throw new AppException('Проверьте начальное и конечное показания одометра.');
        }
        $distance = 0;
        $previous = null;
        $seen = [];
        foreach ($trips as $trip) {
            if (!$this->isForVehicle($trip->vehicle)) {
                throw new AppException('Поездка относится к другому автомобилю.');
            }
            if ($trip->date < $period->from() || $trip->date > $period->to()) {
                throw new AppException('Дата поездки находится вне периода путевого листа.');
            }
            $id = (string) $trip->id;
            if (isset($seen[$id])) {
                throw new AppException('Одна поездка не может учитываться дважды.');
            }
            $seen[$id] = true;
            if (null !== $previous && (!$trip->follows($previous) || $trip->date < $previous->date)) {
                throw new AppException('Поездки путевого листа должны образовывать последовательную цепочку.');
            }
            if ($trip->distanceInKilometers() > PHP_INT_MAX - $distance) {
                throw new AppException('Общий пробег маршрута слишком велик.');
            }
            $distance += $trip->distanceInKilometers();
            if ($distance > PHP_INT_MAX - $this->odometerStart) {
                throw new AppException('Показание одометра слишком велико.');
            }
            $previous = $trip;
        }
        if (null !== $odometerEnd && $distance > $odometerEnd - $this->odometerStart) {
            throw new AppException('Пробег поездок не может превышать разницу показаний одометра.');
        }

        return $distance;
    }

    private function assertUnchangedTripsKeepTheirDeparture(RouteLog ...$trips): void
    {
        if (null === $this->route) {
            return;
        }
        $original = [];
        foreach ($this->route->logs() as $trip) {
            $original[(string) $trip->id] = $trip;
        }
        $remaining = array_fill_keys(array_map(static fn (RouteLog $trip): string => (string) $trip->id, $trips), true);
        foreach ($trips as $trip) {
            $before = $original[(string) $trip->id] ?? null;
            if (null === $before) {
                continue;
            }
            $unchanged = $before->date == $trip->date
                && $before->destination == $trip->destination
                && $before->origin === $trip->origin;
            $previousTripWasRemoved = null !== $before->previous && !isset($remaining[(string) $before->previous->id]);
            $previousTripWasInserted = null !== $trip->previous && !isset($original[(string) $trip->previous->id]);
            if ($unchanged && !$previousTripWasRemoved && !$previousTripWasInserted && !$before->departure()->samePlace($trip->departure())) {
                throw new AppException('Изменение затрагивает следующую поездку. Исправьте её явно.');
            }
        }
    }

    private function assertCompleteTripDistance(int $odometerEnd, int $distance): void
    {
        if ($odometerEnd - $this->odometerStart !== $distance) {
            throw new AppException('Пробег маршрута должен совпадать с разницей показаний одометра.');
        }
    }

    private function assertTripOutsideAbsences(RouteLog $trip, WaybillPeriod ...$absences): void
    {
        foreach ($absences as $absence) {
            if ($absence->contains($trip->date)) {
                throw new AppException('Поездка попадает в период без поездок.');
            }
        }
    }

    private function assertAbsences(WaybillPeriod $period, WaybillPeriod ...$absences): void
    {
        $previous = [];
        foreach ($absences as $absence) {
            if (!$period->contains($absence->from()) || !$period->contains($absence->to())) {
                throw new AppException('Период без поездок должен находиться внутри периода путевого листа.');
            }
            foreach ($previous as $other) {
                if ($absence->overlaps($other)) {
                    throw new AppException('Периоды без поездок не должны пересекаться.');
                }
            }
            $previous[] = $absence;
        }
    }

    private function assertRefuelingPeriods(WaybillPeriod $period, Fuel $fuel, WaybillPeriod ...$absences): void
    {
        foreach ($fuel->refuelingsOrNull()?->entries() ?? [] as $refueling) {
            $date = $refueling->date();
            if (!$period->contains($date)) {
                throw new AppException('Дата заправки находится вне периода путевого листа.');
            }
            foreach ($absences as $absence) {
                if ($absence->contains($date)) {
                    throw new AppException('Заправка попадает в период без поездок.');
                }
            }
        }
    }
}
