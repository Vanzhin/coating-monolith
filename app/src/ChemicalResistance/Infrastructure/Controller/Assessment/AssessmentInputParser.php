<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Assessment;

final class AssessmentInputParser
{
    public static function temperature(mixed $value): ?int
    {
        $str = trim((string) $value);
        if ('' === $str || '0' === $str) {
            return null;
        }
        $int = (int) $str;

        return $int > 0 ? $int : null;
    }

    /** @return list<string> */
    public static function noteIds(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }
        $str = trim((string) $value);
        if ('' === $str) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $str))));
    }
}
