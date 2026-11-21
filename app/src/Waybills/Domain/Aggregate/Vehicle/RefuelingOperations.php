<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Vehicle;

use App\Shared\Infrastructure\Exception\AppException;
use Brick\Math\BigDecimal;

final readonly class RefuelingOperations
{
    /** @var list<FuelOperation> */
    private array $operations;

    public function __construct(FuelOperation ...$operations)
    {
        $seen = [];
        foreach ($operations as $operation) {
            if (FuelOperationType::Refueling !== $operation->type) {
                throw new AppException('В список заправок можно добавить только заправки.');
            }
            $id = (string) $operation->id;
            if (isset($seen[$id])) {
                throw new AppException('Одну заправку нельзя указать дважды.');
            }
            $seen[$id] = true;
        }
        $this->operations = $operations;
    }

    /** @return list<FuelOperation> */
    public function all(): array
    {
        return $this->operations;
    }

    public function total(): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($this->operations as $operation) {
            $total = $total->plus($operation->change);
        }

        return $total;
    }

    /** @return list<FuelType> */
    public function fuelTypes(): array
    {
        $types = [];
        foreach ($this->operations as $operation) {
            $types[$operation->fuelType->value] = $operation->fuelType;
        }

        return array_values($types);
    }
}
