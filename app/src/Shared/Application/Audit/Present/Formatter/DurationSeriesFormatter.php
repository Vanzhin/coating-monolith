<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Present\Formatter;

use App\Shared\Application\Audit\Present\AuditValueFormatter;
use App\Shared\Application\Audit\Present\DurationHumanizer;

/**
 * Форма DryingTimeSeries::jsonSerialize() целиком: непустой список точек
 * `{temperature_at, time_in_minutes, is_calculated}`. Расчётные точки
 * (is_calculated=true) и точки без явного значения (time_in_minutes=null)
 * скрываются — печатаются только явно заданные.
 */
final class DurationSeriesFormatter implements AuditValueFormatter
{
    private const POINT_KEYS = ['is_calculated', 'temperature_at', 'time_in_minutes'];

    public function __construct(private readonly DurationHumanizer $duration)
    {
    }

    public function supports(mixed $value): bool
    {
        if (!is_array($value) || [] === $value || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $point) {
            if (!$this->isPointShape($point)) {
                return false;
            }
        }

        return true;
    }

    public function format(mixed $value): string
    {
        /* @var list<array{temperature_at: mixed, time_in_minutes: mixed, is_calculated: mixed}> $value */
        return $this->formatSeries($value);
    }

    /**
     * Публичный вход для {@see RecoatingTreeFormatter}: та же логика, но без
     * условия «непустой список» — дерево может передать default-серию узла как есть.
     *
     * @param list<array{temperature_at: mixed, time_in_minutes: mixed, is_calculated: mixed}> $points
     */
    public function formatSeries(array $points): string
    {
        $parts = [];
        foreach ($points as $point) {
            if (true === (bool) ($point['is_calculated'] ?? false)) {
                continue;
            }

            $minutes = $point['time_in_minutes'] ?? null;
            if (null === $minutes) {
                continue;
            }

            $parts[] = sprintf('%d °C — %s', (int) $point['temperature_at'], $this->duration->format((int) $minutes));
        }

        return implode('; ', $parts);
    }

    private function isPointShape(mixed $point): bool
    {
        if (!is_array($point)) {
            return false;
        }

        $keys = array_keys($point);
        sort($keys);

        return self::POINT_KEYS === $keys;
    }
}
