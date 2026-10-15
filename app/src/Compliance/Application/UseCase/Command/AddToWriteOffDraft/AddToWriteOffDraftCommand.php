<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\AddToWriteOffDraft;

use App\Shared\Application\Command\Command;

/**
 * «Списать выбранные» с карточки выдачи: добавить held-факты выбранных позиций в открытый черновик акта
 * списания по требованию (создать, если открытого нет). Количества/причины уточняют уже на странице акта.
 */
readonly class AddToWriteOffDraftCommand extends Command
{
    /** @param list<string> $obligationKeys */
    public function __construct(
        public string $profileId,
        public string $requirementId,
        public array $obligationKeys,
    ) {
    }
}
