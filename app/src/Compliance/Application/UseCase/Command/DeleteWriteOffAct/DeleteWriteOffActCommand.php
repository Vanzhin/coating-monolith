<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\DeleteWriteOffAct;

use App\Shared\Application\Command\Command;

/** Удалить черновик акта списания (оформленный — нельзя). */
readonly class DeleteWriteOffActCommand extends Command
{
    public function __construct(
        public string $profileId,
        public string $writeOffActId,
    ) {
    }
}
