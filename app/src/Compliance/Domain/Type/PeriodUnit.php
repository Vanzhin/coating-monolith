<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Type;

/** Единица периода для периодичности «каждые N (мес./лет)». */
enum PeriodUnit: string
{
    case Month = 'month';
    case Year = 'year';

    /** Подпись для выпадающего списка единицы периода. */
    public function title(): string
    {
        return match ($this) {
            self::Month => 'месяцы',
            self::Year => 'годы',
        };
    }

    public function addTo(\DateTimeImmutable $base, int $count): \DateTimeImmutable
    {
        return match ($this) {
            self::Month => $base->modify(sprintf('+%d months', $count)),
            self::Year => $base->modify(sprintf('+%d years', $count)),
        };
    }

    /** Согласованная с числом форма слова (2 года / 5 лет / 1 месяц). */
    public function pluralFor(int $count): string
    {
        $forms = match ($this) {
            self::Month => ['месяц', 'месяца', 'месяцев'],
            self::Year => ['год', 'года', 'лет'],
        };
        $mod100 = $count % 100;
        $mod10 = $count % 10;
        if (1 === $mod10 && 11 !== $mod100) {
            return $forms[0];
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $forms[1];
        }

        return $forms[2];
    }
}
