<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Present\Formatter;

use App\Shared\Application\Audit\Present\AuditValueFormatter;

/** Форма DftRange::jsonSerialize() целиком: `{min, max, tds_dft, type}`. */
final class DftRangeFormatter implements AuditValueFormatter
{
    private const KEYS = ['max', 'min', 'tds_dft', 'type'];

    public function supports(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        $keys = array_keys($value);
        sort($keys);

        return self::KEYS === $keys;
    }

    public function format(mixed $value): string
    {
        /* @var array{min: mixed, max: mixed, tds_dft: mixed, type: mixed} $value */
        return sprintf('%s–%s %s (целевая %s)', $value['min'], $value['max'], $value['type'], $value['tds_dft']);
    }
}
