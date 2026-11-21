<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\CreateDriverProfile;

final readonly class CreateDriverProfileCommandResult
{
    public function __construct(public string $id)
    {
    }
}
