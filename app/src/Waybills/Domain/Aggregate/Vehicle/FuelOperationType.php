<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Vehicle;

enum FuelOperationType: string
{
    case Refueling = 'refueling';
    case Reconciliation = 'reconciliation';
}
