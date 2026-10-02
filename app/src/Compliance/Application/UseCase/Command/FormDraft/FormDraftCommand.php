<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\FormDraft;

use App\Shared\Application\Command\Command;

/** Сформировать черновик карточки одному сотруднику по требованию (ручной одиночный запуск). */
readonly class FormDraftCommand extends Command
{
    public function __construct(
        public string $profileId,
        public string $requirementId,
    ) {
    }
}
