<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Type;

/**
 * Светофор соответствия. НЕ хранится в БД — выводится на чтении из дат обязанности + текущего момента
 * ({@see \App\Compliance\Domain\Service\ComplianceStatusResolver}).
 */
enum ComplianceStatus: string
{
    case Green = 'green';
    case Yellow = 'yellow';
    case Red = 'red';

    public function title(): string
    {
        return match ($this) {
            self::Green => 'В норме',
            self::Yellow => 'Подходит срок',
            self::Red => 'Требует внимания',
        };
    }

    public function severity(): int
    {
        return match ($this) {
            self::Green => 0,
            self::Yellow => 1,
            self::Red => 2,
        };
    }

    /** Худший из двух (для агрегата по человеку/отделу). */
    public static function worseOf(self $a, self $b): self
    {
        return $a->severity() >= $b->severity() ? $a : $b;
    }
}
