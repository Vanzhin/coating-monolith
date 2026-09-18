<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Present;

use App\Shared\Application\Audit\Present\Formatter\JsonFallbackFormatter;

/**
 * Оркестратор цепочки форматтеров: значение отдаётся первому, чей `supports()`
 * true. Гарантия «никогда не бросает» — своя, а не завязанная на то, что
 * вызывающий код правильно собрал DI-цепочку: если форматтеров нет, ни один не
 * подошёл или подошедший упал исключением — результат уходит в pretty-JSON
 * тем же алгоритмом, что и {@see JsonFallbackFormatter} (используется напрямую,
 * без зависимости от порядка регистрации).
 */
final class ValueHumanizer
{
    private readonly JsonFallbackFormatter $fallback;

    /** @var list<AuditValueFormatter> */
    private readonly array $formatters;

    public function __construct(AuditValueFormatter ...$formatters)
    {
        $this->formatters = $formatters;
        $this->fallback = new JsonFallbackFormatter();
    }

    public function humanize(mixed $value): string
    {
        foreach ($this->formatters as $formatter) {
            try {
                if ($formatter->supports($value)) {
                    return $formatter->format($value);
                }
            } catch (\Throwable) {
                return $this->fallback->format($value);
            }
        }

        return $this->fallback->format($value);
    }
}
