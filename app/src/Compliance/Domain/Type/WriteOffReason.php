<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Type;

/** Причина списания СИЗ (в акт списания). */
enum WriteOffReason: string
{
    case PhysicalWear = 'physical_wear';
    case TermExpired = 'term_expired';

    public function title(): string
    {
        return match ($this) {
            self::PhysicalWear => 'Физический износ',
            self::TermExpired => 'Окончание нормативного срока эксплуатации',
        };
    }
}
