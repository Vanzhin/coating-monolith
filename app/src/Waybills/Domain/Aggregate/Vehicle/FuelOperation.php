<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Vehicle;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use Brick\Math\BigDecimal;
use Symfony\Component\Uid\Uuid;

final class FuelOperation
{
    public function __construct(
        public private(set) Uuid $id,
        public readonly Vehicle $vehicle,
        public readonly \DateTimeImmutable $date,
        public readonly FuelType $fuelType,
        public readonly BigDecimal $change,
        public readonly FuelOperationType $type,
        public readonly ?Waybill $waybill = null,
    ) {
        if ($date < $vehicle->initialAt()) {
            throw new AppException('Дата топливной операции не может быть раньше начала истории автомобиля.');
        }
        if (!$vehicle->supportsFuelType($fuelType)) {
            throw new AppException('Выбранный вид топлива не указан для автомобиля.');
        }
        if (FuelOperationType::Refueling === $type && !$change->isPositive()) {
            throw new AppException('Количество заправленного топлива должно быть положительным.');
        }
        if (FuelOperationType::Reconciliation === $type && null === $waybill) {
            throw new AppException('Для сверки топлива укажите путевой лист.');
        }
        if (null !== $waybill && !$waybill->isForVehicle($vehicle)) {
            throw new AppException('Путевой лист относится к другому автомобилю.');
        }
    }

    public static function reconcile(
        Uuid $id,
        Vehicle $vehicle,
        \DateTimeImmutable $date,
        FuelType $fuelType,
        BigDecimal $calculatedRemaining,
        BigDecimal $actualRemaining,
        Waybill $waybill,
    ): self {
        if ($calculatedRemaining->isNegative()) {
            throw new AppException('Расчётный остаток топлива не может быть отрицательным.');
        }
        if ($actualRemaining->isNegative()) {
            throw new AppException('Фактический остаток топлива не может быть отрицательным.');
        }

        return new self(
            $id,
            $vehicle,
            $date,
            $fuelType,
            $actualRemaining->minus($calculatedRemaining),
            FuelOperationType::Reconciliation,
            $waybill,
        );
    }

    public function belongsTo(Vehicle $vehicle): bool
    {
        return $this->vehicle->getId() === $vehicle->getId();
    }
}
