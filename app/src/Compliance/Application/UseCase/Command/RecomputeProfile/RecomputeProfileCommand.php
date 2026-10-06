<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\RecomputeProfile;

use App\Shared\Application\Command\Command;

/** Принудительная пересборка проекции учёта одного человека (ручной fallback админа). */
readonly class RecomputeProfileCommand extends Command
{
    public function __construct(public string $profileId)
    {
    }
}
