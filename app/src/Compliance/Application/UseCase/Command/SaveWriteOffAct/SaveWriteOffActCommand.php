<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SaveWriteOffAct;

use App\Shared\Application\Command\Command;

/** Сохранить состав акта списания (на его странице): по каждой позиции — количество к списанию и причина. */
readonly class SaveWriteOffActCommand extends Command
{
    /** @param list<array{recordId: string, quantity: float, reason: string}> $lines */
    public function __construct(
        public string $profileId,
        public string $writeOffActId,
        public array $lines,
    ) {
    }
}
