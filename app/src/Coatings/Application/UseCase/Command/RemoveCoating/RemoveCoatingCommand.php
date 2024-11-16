<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\RemoveCoating;

use App\Shared\Application\Command\Command;

readonly class RemoveCoatingCommand extends Command
{
    public function __construct(public string $id)
    {
    }
}
