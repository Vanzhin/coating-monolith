<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\UpdateCoating;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Shared\Application\Command\Command;

readonly class UpdateCoatingCommand extends Command
{
    public function __construct(
        public string     $coatingId,
        public CoatingDTO $coatingDTO,
    )
    {
    }
}
