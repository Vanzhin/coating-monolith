<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\CreateProfile;

final readonly class CreateProfileCommandResult
{
    public function __construct(public string $id)
    {
    }
}
