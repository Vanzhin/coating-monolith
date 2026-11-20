<?php

declare(strict_types=1);

namespace App\Waybills\Application\Service;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Application\DTO\RefuelingInputs;
use App\Waybills\Domain\Aggregate\Vehicle\FuelOperation;
use App\Waybills\Domain\Aggregate\Vehicle\FuelOperationType;
use App\Waybills\Domain\Aggregate\Vehicle\RefuelingOperations;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use Symfony\Component\Uid\Uuid;

final class RefuelingOperationBuilder
{
    public function build(Waybill $waybill, ?RefuelingInputs $inputs): RefuelingOperations
    {
        $existing = $waybill->refuelingOperations();
        if (null === $inputs) {
            return $existing;
        }
        $known = [];
        foreach ($existing->all() as $operation) {
            $known[(string) $operation->id] = $operation;
        }
        $operations = [];
        foreach ($inputs->items as $input) {
            $original = null === $input->id ? null : ($known[(string) $input->id] ?? null);
            if (null !== $input->id && null === $original) {
                throw new AppException('Заправка не принадлежит этому черновику.');
            }
            $operations[] = new FuelOperation($input->id ?? Uuid::v7(), $waybill->vehicle(), $input->date, $input->fuelType, $input->quantity, FuelOperationType::Refueling, $original?->waybill);
        }

        return new RefuelingOperations(...$operations);
    }
}
