<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Present\Formatter;

use App\Shared\Application\Audit\Present\AuditValueFormatter;

/**
 * Крайний случай цепочки: форма значения не распознана ни одним специализированным
 * форматтером. Печатает pretty JSON с кириллицей без `\uXXXX`-escaping.
 */
final class JsonFallbackFormatter implements AuditValueFormatter
{
    public function supports(mixed $value): bool
    {
        return true;
    }

    public function format(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return is_string($json) ? $json : '';
    }
}
