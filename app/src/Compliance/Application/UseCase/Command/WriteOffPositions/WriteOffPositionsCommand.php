<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\WriteOffPositions;

use App\Shared\Application\Command\Command;

/** Положить материальные позиции требования в корзину акта списания (черновик). Эффекта нет — он на оформлении акта. */
readonly class WriteOffPositionsCommand extends Command
{
    /** @param list<string> $obligationKeys */
    public function __construct(
        public string $profileId,
        public string $requirementId,
        public array $obligationKeys,
    ) {
    }
}
