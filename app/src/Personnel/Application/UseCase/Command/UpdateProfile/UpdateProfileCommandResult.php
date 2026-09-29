<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\UpdateProfile;

final readonly class UpdateProfileCommandResult
{
    public function __construct(public string $id)
    {
    }
}
