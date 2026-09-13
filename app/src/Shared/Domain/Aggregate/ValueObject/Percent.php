<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Процент — число в диапазоне [0; 100]. Узкий владелец инварианта «валидный процент»,
 * чтобы не дублировать проверку по домену (сухой остаток, разбавление и т.п.). Ноль разрешён
 * (напр. разбавление 0 % — неразбавленная краска). Где нужен строго положительный процент —
 * проверяет потребитель.
 */
final readonly class Percent
{
    public function __construct(private int|float $value)
    {
        if ($value < 0 || $value > 100) {
            throw new AppException('Процент должен быть в диапазоне от 0 до 100.');
        }
    }

    public function value(): int|float
    {
        return $this->value;
    }
}
