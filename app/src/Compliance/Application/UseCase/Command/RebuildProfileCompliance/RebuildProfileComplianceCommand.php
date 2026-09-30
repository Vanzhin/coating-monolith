<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\RebuildProfileCompliance;

use App\Shared\Application\Command\Command;

/** Пересобрать проекцию учёта одного человека из текущей нормы+фактов (enroll/refresh из UI). */
readonly class RebuildProfileComplianceCommand extends Command
{
    public function __construct(public string $profileId)
    {
    }
}
