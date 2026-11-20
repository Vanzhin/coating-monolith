<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Vehicle;

enum FuelType: string
{
    case Ai92 = 'АИ-92';
    case Ai95 = 'АИ-95';
    case Ai98 = 'АИ-98';
    case Ai100 = 'АИ-100';
}
