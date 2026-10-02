<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\DeleteDraft;

use App\Shared\Application\Command\Command;

/** Удалить открытый черновик карточки (подписанный акт не трогается). */
readonly class DeleteDraftCommand extends Command
{
    public function __construct(
        public string $profileId,
        public string $documentId,
    ) {
    }
}
