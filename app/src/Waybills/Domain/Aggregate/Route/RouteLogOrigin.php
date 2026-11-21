<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Route;

enum RouteLogOrigin: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
}
