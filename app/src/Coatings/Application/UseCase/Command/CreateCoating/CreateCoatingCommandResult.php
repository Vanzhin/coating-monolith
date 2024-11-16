<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\CreateCoating;

class CreateCoatingCommandResult
{
    public function __construct(
        public string $id,
    )
    {
    }
}
