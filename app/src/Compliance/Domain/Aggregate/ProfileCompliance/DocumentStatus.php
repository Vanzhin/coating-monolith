<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

/** Цикл акта выдачи (личной карточки): черновик → подписан (заморожен). */
enum DocumentStatus: string
{
    case Formed = 'formed';
    case Signed = 'signed';

    public function title(): string
    {
        return match ($this) {
            self::Formed => 'Черновик',
            self::Signed => 'Подписан',
        };
    }
}
