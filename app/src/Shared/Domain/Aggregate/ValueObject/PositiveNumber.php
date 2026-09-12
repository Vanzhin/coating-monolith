<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Положительное число — целое или дробное (> 0). Узкий владелец инварианта «положительность»,
 * чтобы не дублировать проверку по агрегатам/VO. Для диапазонов — PositiveNumberRange.
 */
final readonly class PositiveNumber
{
    public function __construct(private int|float $value)
    {
        if ($value <= 0) {
            throw new AppException('Значение должно быть положительным.');
        }
    }

    public function value(): int|float
    {
        return $this->value;
    }
}
