<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\CreateLocation;

use App\Shared\Application\Command\Command;
use App\Waybills\Domain\Aggregate\Route\Location;

final readonly class CreateLocationCommand extends Command
{
    public function __construct(public Location $location)
    {
    }
}
