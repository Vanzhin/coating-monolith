<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\DeleteProfile;

use App\Shared\Application\Command\Command;

final readonly class DeleteProfileCommand extends Command
{
    public function __construct(public string $id)
    {
    }
}
