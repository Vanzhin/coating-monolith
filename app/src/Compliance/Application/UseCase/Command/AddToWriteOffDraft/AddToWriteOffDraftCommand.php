<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\AddToWriteOffDraft;

use App\Shared\Application\Command\Command;

/**
 * «Списать выбранные/всё» с карточки выдачи: добавить held-факты человека в открытый черновик акта списания
 * пары (требование × организация) — создать, если открытого нет. Количества/причины уточняют на странице
 * акта. Акт org-scoped и кросс-персон: несколько человек одной организации по одному требованию попадают
 * в ОДИН черновик.
 */
readonly class AddToWriteOffDraftCommand extends Command
{
    /** @param ?list<string> $obligationKeys null = «Списать всё» (все инвентарные held>0 позиции требования) */
    public function __construct(
        public string $profileId,
        public string $requirementId,
        public ?array $obligationKeys,
    ) {
    }
}
