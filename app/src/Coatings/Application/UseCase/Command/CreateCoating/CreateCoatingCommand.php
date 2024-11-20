<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\CreateCoating;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Shared\Application\Command\Command;

readonly class CreateCoatingCommand extends Command
{
    public function __construct(public CoatingDTO $dto)
    {
    }
}
