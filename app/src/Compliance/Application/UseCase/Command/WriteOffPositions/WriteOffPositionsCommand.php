<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\WriteOffPositions;

use App\Shared\Application\Command\Command;

/** Положить порции фактов в корзину акта списания (черновик). Эффекта нет — он на оформлении акта. */
readonly class WriteOffPositionsCommand extends Command
{
    /** @param list<array{recordId: string, quantity: float}> $portions */
    public function __construct(
        public string $profileId,
        public string $requirementId,
        public array $portions,
    ) {
    }
}
