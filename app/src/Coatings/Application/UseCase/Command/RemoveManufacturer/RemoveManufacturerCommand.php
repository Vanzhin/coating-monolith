<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\RemoveManufacturer;

use App\Shared\Application\Command\Command;

readonly class RemoveManufacturerCommand extends Command
{
    public function __construct(public string $id)
    {
    }
}
