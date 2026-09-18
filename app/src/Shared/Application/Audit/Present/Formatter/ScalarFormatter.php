<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Present\Formatter;

use App\Shared\Application\Audit\Present\AuditValueFormatter;

/** Голый скаляр (или null) — последняя ступень перед JSON-fallback. */
final class ScalarFormatter implements AuditValueFormatter
{
    public function supports(mixed $value): bool
    {
        return is_bool($value) || null === $value || is_int($value) || is_float($value) || is_string($value);
    }

    public function format(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Да' : 'Нет';
        }

        if (null === $value) {
            return '—';
        }

        return (string) $value;
    }
}
