<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\CancelWriteOffItem;

use App\Shared\Application\Command\Command;

/** Откат: вынуть позицию из корзины акта списания (пока он черновик); опустевший акт удаляется. */
readonly class CancelWriteOffItemCommand extends Command
{
    public function __construct(
        public string $profileId,
        public string $writeOffActId,
        public string $portionId,
    ) {
    }
}
