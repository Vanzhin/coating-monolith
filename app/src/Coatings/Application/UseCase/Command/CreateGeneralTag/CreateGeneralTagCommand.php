<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateGeneralTag;

use App\Shared\Application\Command\Command;

final readonly class CreateGeneralTagCommand extends Command
{
    public function __construct(public string $title)
    {
    }
}
