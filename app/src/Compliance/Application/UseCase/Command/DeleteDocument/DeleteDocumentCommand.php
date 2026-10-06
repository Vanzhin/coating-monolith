<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\DeleteDocument;

use App\Shared\Application\Command\Command;

/** Админское удаление документа выдачи (в т.ч. подписанного) с каскадом списаний. */
readonly class DeleteDocumentCommand extends Command
{
    public function __construct(
        public string $profileId,
        public string $documentId,
    ) {
    }
}
