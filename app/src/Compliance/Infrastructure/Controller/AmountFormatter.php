<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller;

/** Отображение количества в карточках/актах учёта — без хвостового «.0» у целых значений. */
final class AmountFormatter
{
    public static function trimmed(float $amount): string
    {
        return 0.0 === fmod($amount, 1.0) ? (string) (int) $amount : (string) $amount;
    }
}
