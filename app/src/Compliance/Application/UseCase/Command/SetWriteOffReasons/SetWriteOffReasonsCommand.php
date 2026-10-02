<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SetWriteOffReasons;

use App\Shared\Application\Command\Command;

/** Проставить причины позициям корзины акта списания (на его странице, пока черновик). */
readonly class SetWriteOffReasonsCommand extends Command
{
    /** @param array<string, string> $reasons portionId → значение причины ({@see \App\Compliance\Domain\Type\WriteOffReason}) */
    public function __construct(
        public string $profileId,
        public string $writeOffActId,
        public array $reasons,
    ) {
    }
}
