<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Vehicle;

use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use Brick\Math\BigDecimal;
use Symfony\Component\Uid\Uuid;

class Vehicle extends Aggregate
{
    private string $model;
    /** @var list<FuelType> */
    private array $fuelTypes;
    private FuelType $defaultFuelType;
    private int $currentOdometer;
    private BigDecimal $fuelRemaining;
    private ?DriverProfile $driver = null;

    public function __construct(
        private Uuid $id,
        string $model,
        private RegistrationNumber $registrationNumber,
        private \DateTimeImmutable $initialAt,
        private Location $initialLocation,
        private int $initialOdometer,
        private BigDecimal $initialFuelRemaining,
        private ?PositiveNumber $consumptionRate = null,
        FuelType $defaultFuelType = FuelType::Ai95,
        FuelType ...$fuelTypes,
    ) {
        if ($initialOdometer < 0) {
            throw new AppException('Начальное показание одометра должно быть неотрицательным.');
        }
        $this->assertFuelRemaining($initialFuelRemaining);
        $this->currentOdometer = $initialOdometer;
        $this->fuelRemaining = $initialFuelRemaining;
        $this->rename($model);
        $this->changeFuelTypes($defaultFuelType, ...([] === $fuelTypes ? [$defaultFuelType] : $fuelTypes));
        $this->setConsumptionRate($consumptionRate);
    }

    public function rename(string $model): void
    {
        $model = trim($model);
        if (1 === preg_match('/\A[\s\p{Z}]*\z/u', $model)) {
            throw new AppException('Укажите марку и модель автомобиля.');
        }
        $this->model = $model;
    }

    public function enter(DriverProfile $driver): void
    {
        if (null !== $this->driver) {
            throw new AppException('В автомобиле уже есть водитель.');
        }

        $this->driver = $driver;
    }

    public function leave(DriverProfile $driver): void
    {
        if (null === $this->driver || $this->driver->getId() !== $driver->getId()) {
            throw new AppException('Водитель не находится в этом автомобиле.');
        }

        $this->driver = null;
    }

    public function driver(): ?DriverProfile
    {
        return $this->driver;
    }

    public function isDrivenBy(string $ownerId): bool
    {
        return $this->driver?->isOwnedBy($ownerId) ?? false;
    }

    public function changeRegistrationNumber(RegistrationNumber $registrationNumber): void
    {
        $this->registrationNumber = $registrationNumber;
    }

    public function changeFuelTypes(FuelType $defaultFuelType, FuelType ...$fuelTypes): void
    {
        if (!in_array($defaultFuelType, $fuelTypes, true)) {
            throw new AppException('Топливо по умолчанию должно входить в список видов топлива автомобиля.');
        }
        $uniqueFuelTypes = [];
        foreach ($fuelTypes as $fuelType) {
            $uniqueFuelTypes[$fuelType->value] = $fuelType;
        }
        $this->fuelTypes = array_values($uniqueFuelTypes);
        $this->defaultFuelType = $defaultFuelType;
    }

    public function setConsumptionRate(?PositiveNumber $consumptionRate): void
    {
        if (null !== $consumptionRate && !is_finite((float) $consumptionRate->value())) {
            throw new AppException('Норма расхода должна быть конечным числом.');
        }
        $this->consumptionRate = $consumptionRate;
    }

    public function initialAt(): \DateTimeImmutable
    {
        return $this->initialAt;
    }

    public function initialLocation(): Location
    {
        return $this->initialLocation;
    }

    public function initialOdometer(): int
    {
        return $this->initialOdometer;
    }

    public function initialFuelRemaining(): BigDecimal
    {
        return $this->initialFuelRemaining;
    }

    public function currentOdometer(): int
    {
        return $this->currentOdometer;
    }

    public function fuelRemaining(): BigDecimal
    {
        return $this->fuelRemaining;
    }

    public function fuelRemainingAfter(FuelOperation ...$operations): BigDecimal
    {
        $remaining = $this->initialFuelRemaining;
        $seen = [];
        foreach ($operations as $operation) {
            if (!$operation->belongsTo($this)) {
                throw new AppException('Топливная операция относится к другому автомобилю.');
            }
            $id = (string) $operation->id;
            if (isset($seen[$id])) {
                throw new AppException('Одна топливная операция не может учитываться дважды.');
            }
            $seen[$id] = true;
            $remaining = $remaining->plus($operation->change);
        }
        $this->assertFuelRemaining($remaining);

        return $remaining;
    }

    public function approveRoute(Route $route, ?RouteLog $lastApprovedLog, BigDecimal $fuelRemaining): void
    {
        if ($route->vehicle()->getId() !== $this->getId()) {
            throw new AppException('Маршрут относится к другому автомобилю.');
        }
        if (null !== $lastApprovedLog && $lastApprovedLog->vehicle->getId() !== $this->getId()) {
            throw new AppException('Последняя поездка относится к другому автомобилю.');
        }
        $this->assertFuelRemaining($fuelRemaining);

        $first = $route->first();
        if (null !== $first) {
            if ($first->previous !== $lastApprovedLog) {
                throw new AppException('Состояние автомобиля изменилось. Проверьте маршрут перед утверждением.');
            }

            $last = $route->last();
            if (null === $last) {
                throw new \LogicException('Маршрут с первой поездкой должен иметь последнюю.');
            }
            if ($route->distance() > PHP_INT_MAX - $this->currentOdometer) {
                throw new AppException('Показание одометра слишком велико.');
            }

            $this->currentOdometer += $route->distance();
        }
        $this->fuelRemaining = $fuelRemaining;
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getRegistrationNumber(): RegistrationNumber
    {
        return $this->registrationNumber;
    }

    /** @return list<FuelType> */
    public function getFuelTypes(): array
    {
        return $this->fuelTypes;
    }

    public function getDefaultFuelType(): FuelType
    {
        return $this->defaultFuelType;
    }

    public function supportsFuelType(FuelType $fuelType): bool
    {
        return in_array($fuelType, $this->fuelTypes, true);
    }

    public function getConsumptionRate(): ?PositiveNumber
    {
        return $this->consumptionRate;
    }

    private function assertFuelRemaining(BigDecimal $fuelRemaining): void
    {
        if ($fuelRemaining->isNegative()) {
            throw new AppException('Остаток топлива должен быть неотрицательным числом.');
        }
    }
}
