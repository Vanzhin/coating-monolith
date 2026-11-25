<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\CreateLocation;

use App\Waybills\Domain\Aggregate\Route\Location;

final readonly class CreateLocationCommandResult
{
    public function __construct(public Location $location)
    {
    }
}
