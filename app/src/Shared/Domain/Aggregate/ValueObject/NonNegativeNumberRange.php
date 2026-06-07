<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Валидный диапазон, который принимает только неотрицательные числа (0 и выше).
 */
final class NonNegativeNumberRange extends NumberRange
{
    /**
     * Дополнительное бизнес-правило: значения не могут быть меньше нуля.
     */
    protected function validate(int|float $min, int|float $max): void
    {
        if ($min < 0 || $max < 0) {
            throw new AppException(
                sprintf('Значения диапазона не могут быть отрицательными. Передано: min=%s, max=%s', $min, $max)
            );
        }
    }
}
