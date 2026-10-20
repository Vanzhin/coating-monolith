<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\ExcludeObligation;

use App\Shared\Application\Command\Command;

/**
 * Исключить позицию (обязанность) из учёта конкретного человека — «Убрать позицию» на карточке выдачи. Уходит из
 * контроля/списков (/issue, дашборд), факты журнала (выдачи/списания) сохраняются. Чаще применяется к личной
 * (off-norm) позиции, но ключ-механизм общий.
 */
readonly class ExcludeObligationCommand extends Command
{
    public function __construct(
        public string $profileId,
        public string $requirementId,
        public string $obligationKey,
    ) {
    }
}
