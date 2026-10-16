<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\DeleteWriteOffAct;

use App\Shared\Application\Command\Command;

/** Удалить черновик акта списания (оформленный — нельзя). Акт — самостоятельный кросс-персон агрегат, по его id. */
readonly class DeleteWriteOffActCommand extends Command
{
    public function __construct(
        public string $writeOffActId,
    ) {
    }
}
