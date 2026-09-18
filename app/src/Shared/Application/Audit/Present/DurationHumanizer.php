<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Present;

/**
 * Разложение длительности в минутах на человекочитаемую строку (сут/ч/мин).
 * Не форматтер значения — переиспользуемый helper для форматтеров, у которых
 * встречается длительность (серии точек, пределы термостойкости).
 */
final class DurationHumanizer
{
    private const MINUTES_PER_DAY = 1440;
    private const MINUTES_PER_HOUR = 60;

    /**
     * null → «нет данных» (не задокументировано); 0 → «без ограничения» (домен:
     * ноль минут = без предела). Иначе — точное разложение на сут/ч/мин, берутся
     * максимум 2 старшие ненулевые единицы в порядке сут→ч→мин, без округления.
     */
    public function format(?int $minutes): string
    {
        if (null === $minutes) {
            return 'нет данных';
        }

        if (0 === $minutes) {
            return 'без ограничения';
        }

        $sign = $minutes < 0 ? '-' : '';
        $remaining = abs($minutes);

        $days = intdiv($remaining, self::MINUTES_PER_DAY);
        $remaining %= self::MINUTES_PER_DAY;
        $hours = intdiv($remaining, self::MINUTES_PER_HOUR);
        $mins = $remaining % self::MINUTES_PER_HOUR;

        $units = array_filter(
            ['сут' => $days, 'ч' => $hours, 'мин' => $mins],
            static fn (int $value): bool => $value > 0,
        );
        $topTwo = array_slice($units, 0, 2, true);

        $parts = array_map(
            static fn (int $value, string $unit): string => $value.' '.$unit,
            $topTwo,
            array_keys($topTwo),
        );

        return $sign.implode(' ', $parts);
    }
}
