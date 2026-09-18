<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Present\Formatter;

use App\Shared\Application\Audit\Present\AuditValueFormatter;

/**
 * Форма MixingRatio::jsonSerialize(): ключи — подмножество `{volume, mass}` и
 * только они. Каждая часть — список чисел или null; пустые опускаются.
 */
final class MixingRatioFormatter implements AuditValueFormatter
{
    private const ALLOWED_KEYS = ['mass', 'volume'];

    public function supports(mixed $value): bool
    {
        if (!is_array($value) || [] === $value) {
            return false;
        }

        return [] === array_diff(array_keys($value), self::ALLOWED_KEYS);
    }

    public function format(mixed $value): string
    {
        /** @var array{volume?: mixed, mass?: mixed} $value */
        $parts = array_filter([
            $this->formatParts('по объёму', $value['volume'] ?? null),
            $this->formatParts('по массе', $value['mass'] ?? null),
        ], static fn (string $part): bool => '' !== $part);

        return implode('; ', $parts);
    }

    private function formatParts(string $label, mixed $ratio): string
    {
        if (!is_array($ratio) || [] === $ratio) {
            return '';
        }

        return $label.' '.implode(':', $ratio);
    }
}
