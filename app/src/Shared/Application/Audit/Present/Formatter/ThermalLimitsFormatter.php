<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Present\Formatter;

use App\Shared\Application\Audit\Present\AuditValueFormatter;
use App\Shared\Application\Audit\Present\DurationHumanizer;

/**
 * Форма ThermalExposureLimits::jsonSerialize(): ключи — подмножество
 * `{continuous_min, continuous_max, peak_max, peak_duration_minutes}` и только
 * они (иначе легко спутать с чужой формой). Пустые (null) части опускаются.
 */
final class ThermalLimitsFormatter implements AuditValueFormatter
{
    private const ALLOWED_KEYS = ['continuous_min', 'continuous_max', 'peak_max', 'peak_duration_minutes'];

    public function __construct(private readonly DurationHumanizer $duration)
    {
    }

    public function supports(mixed $value): bool
    {
        if (!is_array($value) || [] === $value) {
            return false;
        }

        return [] === array_diff(array_keys($value), self::ALLOWED_KEYS);
    }

    public function format(mixed $value): string
    {
        /** @var array{continuous_min?: mixed, continuous_max?: mixed, peak_max?: mixed, peak_duration_minutes?: mixed} $value */
        $parts = array_filter([
            $this->formatContinuous($value['continuous_min'] ?? null, $value['continuous_max'] ?? null),
            $this->formatPeak($value['peak_max'] ?? null, $value['peak_duration_minutes'] ?? null),
        ], static fn (string $part): bool => '' !== $part);

        return implode('; ', $parts);
    }

    private function formatContinuous(mixed $min, mixed $max): string
    {
        $hasMin = null !== $min;
        $hasMax = null !== $max;

        if ($hasMin && $hasMax) {
            return sprintf('непрерывно %s…%s °C', $this->signed((int) $min), $this->signed((int) $max));
        }

        if ($hasMax) {
            return sprintf('непрерывно до %s °C', $this->signed((int) $max));
        }

        if ($hasMin) {
            return sprintf('непрерывно от %s °C', $this->signed((int) $min));
        }

        return '';
    }

    private function formatPeak(mixed $peakMax, mixed $peakDurationMinutes): string
    {
        if (null === $peakMax) {
            return '';
        }

        $result = sprintf('пик %s °C', $this->signed((int) $peakMax));
        if (null !== $peakDurationMinutes) {
            $result .= sprintf(' до %s', $this->duration->format((int) $peakDurationMinutes));
        }

        return $result;
    }

    private function signed(int $value): string
    {
        return ($value < 0 ? "\u{2212}" : '+').abs($value);
    }
}
