<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\FormDraftsForRequirement;

use App\Shared\Application\Command\Command;

/** Сформировать черновики карточек всем сотрудникам должностей требования (пакетно, за админа). */
readonly class FormDraftsForRequirementCommand extends Command
{
    public function __construct(public string $requirementId)
    {
    }
}
