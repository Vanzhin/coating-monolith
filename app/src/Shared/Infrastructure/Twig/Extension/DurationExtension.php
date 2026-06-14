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
        ];
    }

    // ─── Полный формат ──────────────────────────────────────────

    /** Принимает CarbonInterval (для DTO/getInterval). */
    public function format(?CarbonInterval $interval): string
    {
        if ($interval === null) {
            return '—';
        }
        return $interval->copy()->locale('ru')->cascade()->forHumans(['parts' => 2]);
    }

    /** Принимает int минут — отдаёт «5 дней 16 часов». */
    public function formatMinutes(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        return $this->format(CarbonInterval::minutes($minutes));
    }

    // ─── Короткий формат ────────────────────────────────────────

    public function formatShort(?CarbonInterval $interval): string
    {
        if ($interval === null) {
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
        if ($minutes === null) {
            return '—';
        }
        if ($minutes === 0) {
            return '0 мин';
        }

        $days = intdiv($minutes, 1440);
        $rem = $minutes - $days * 1440;
        $hours = intdiv($rem, 60);
        $mins = $rem - $hours * 60;

        $parts = [];
        if ($days > 0)  { $parts[] = $days . ' д'; }
        if ($hours > 0) { $parts[] = $hours . ' ч'; }
        if ($mins > 0)  { $parts[] = $mins . ' мин'; }

        return implode(' ', array_slice($parts, 0, 2));
    }
}
