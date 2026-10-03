<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\StartWriteOffAct;

use App\Shared\Application\Command\Command;

/** Открыть черновик акта списания по требованию (создать пустой, если открытого нет) — кнопка «Перейти к акту списания». */
readonly class StartWriteOffActCommand extends Command
{
    public function __construct(
        public string $profileId,
        public string $requirementId,
    ) {
    }
}
