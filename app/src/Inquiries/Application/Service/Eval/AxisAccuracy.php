<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Service\Eval;

/**
 * Точность извлечения по одной оси подбора на эталоне (одобренные запросы). total — в скольких
 * запросах ось была в игре (робот ИЛИ человек её заполнил). agreed — робот совпал с одобренным;
 * missed — робот пропустил (человек задал); extra — робот дал лишнее (человек убрал); mismatch —
 * оба заполнили по-разному.
 */
final readonly class AxisAccuracy
{
    public function __construct(
        public string $axis,
        public int $total,
        public int $agreed,
        public int $missed,
        public int $extra,
        public int $mismatch,
    ) {
    }

    /** Доля совпадений, 0..1. */
    public function accuracy(): float
    {
        return 0 === $this->total ? 1.0 : $this->agreed / $this->total;
    }
}
