<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO\View;

/**
 * Строка дашборда точности: человекочитаемая подпись оси + счётчики. Оси с общей подписью
 * (тепловая нагрузка, диапазоны) схлопнуты в одну строку.
 */
final readonly class AxisAccuracyRow
{
    public function __construct(
        public string $label,
        public int $total,
        public int $agreed,
        public int $missed,
        public int $extra,
        public int $mismatch,
    ) {
    }

    /** Доля совпадений 0..1. */
    public function accuracy(): float
    {
        return 0 === $this->total ? 1.0 : $this->agreed / $this->total;
    }
}
