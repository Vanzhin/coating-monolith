<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SaveWriteOffAct;

use App\Shared\Application\Command\Command;

/**
 * Сохранить черновик акта списания: состав (по позиции — количество + причина) + реквизиты (№/дата/комиссия),
 * без подписи. Эффект на фактах — только при оформлении ({@see SignWriteOffAct}).
 */
readonly class SaveWriteOffActCommand extends Command
{
    /**
     * @param list<array{recordId: string, quantity: float, reason: string}> $lines
     * @param list<array<string, mixed>>                                     $members строки комиссии (как в оформлении)
     */
    public function __construct(
        public string $profileId,
        public string $writeOffActId,
        public array $lines,
        public string $actNumber = '',
        public string $actDate = '',
        public array $members = [],
        public string $orderNumber = '',
        public string $orderDate = '',
        public string $representativePosition = '',
        public string $representativeFio = '',
    ) {
    }
}
