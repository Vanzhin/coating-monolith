<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Twig\Extension;

use Carbon\CarbonInterval;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class DurationExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            // Полный формат (Carbon forHumans с ru-локалью): «5 дней 16 часов».
            new TwigFilter('duration_minutes', [$this, 'formatMinutes']),
            new TwigFilter('duration', [$this, 'format']),
            // Короткий формат «5 д 16 ч». Для компактных мест (форма, таблицы).
            new TwigFilter('duration_minutes_short', [$this, 'formatMinutesShort']),
            new TwigFilter('duration_short', [$this, 'formatShort']),
            // Русская плюрализация: {{ 5|plural_ru('час', 'часа', 'часов') }} → «часов».
            new TwigFilter('plural_ru', [$this, 'pluralRu']),
        ];
    }

    /**
     * Русская плюрализация числительного. Возвращает СУФФИКС, не готовое
     * словосочетание — числовую часть шаблон рендерит сам.
     *
     *   {{ n }} {{ n|plural_ru('час', 'часа', 'часов') }}
     *
     * Правила (для целых частей чисел):
     *   - последние две цифры 11..14 → many (одиннадцать часов)
     *   - последняя цифра 1 → one   (21 час)
     *   - последняя цифра 2..4 → few (22 часа)
     *   - иначе → many                (25 часов)
     */
    public function pluralRu(int|float $n, string $one, string $few, string $many): string
    {
        $abs = (int) abs((int) $n);
        $mod100 = $abs % 100;
        $mod10 = $abs % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }
        if (1 === $mod10) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4) {
            return $few;
        }

        return $many;
    }

    // ─── Полный формат ──────────────────────────────────────────

    /** Принимает CarbonInterval (для DTO/getInterval). */
    public function format(?CarbonInterval $interval): string
    {
        if (null === $interval) {
            return '—';
        }

        return $interval->copy()->locale('ru')->cascade()->forHumans(['parts' => 2]);
    }

    /** Принимает int минут — отдаёт «5 дней 16 часов». */
    public function formatMinutes(?int $minutes): string
    {
        if (null === $minutes) {
            return '—';
        }

        return $this->format(CarbonInterval::minutes($minutes));
    }

    // ─── Короткий формат ────────────────────────────────────────

    public function formatShort(?CarbonInterval $interval): string
    {
        if (null === $interval) {
            return '—';
        }

        return $this->formatMinutesShort((int) round($interval->totalMinutes));
    }

    /**
     * Компактный человеческий формат «5 д 16 ч», «12 мин», «2 ч 30 мин».
     * Показываем не более двух старших ненулевых единиц.
     */
    public function formatMinutesShort(?int $minutes): string
    {
        if (null === $minutes) {
            return '—';
        }
        if (0 === $minutes) {
            return '0 мин';
        }

        $days = intdiv($minutes, 1440);
        $rem = $minutes - $days * 1440;
        $hours = intdiv($rem, 60);
        $mins = $rem - $hours * 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = $days.' д';
        }
        if ($hours > 0) {
            $parts[] = $hours.' ч';
        }
        if ($mins > 0) {
            $parts[] = $mins.' мин';
        }

        return implode(' ', array_slice($parts, 0, 2));
    }
}
