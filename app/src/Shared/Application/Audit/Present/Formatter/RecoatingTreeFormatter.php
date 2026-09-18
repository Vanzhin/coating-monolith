<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Present\Formatter;

use App\Shared\Application\Audit\Present\AuditValueFormatter;

/**
 * Форма RecoatingIntervalTree::jsonSerialize() целиком: `{default, children}`.
 * `default` — серия точек узла, рендерится через {@see DurationSeriesFormatter}.
 * Для каждого прямого ребёнка (children может прийти как `[]` или как
 * ассоциативный `{key: node}` — обе формы даёт json_decode(..., true) исходного
 * `{}`/непустого объекта) добавляется его собственная default-серия — без
 * рекурсии в children ребёнка (D4: только один уровень вглубь).
 */
final class RecoatingTreeFormatter implements AuditValueFormatter
{
    private const KEYS = ['children', 'default'];

    public function __construct(private readonly DurationSeriesFormatter $seriesFormatter)
    {
    }

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
        /** @var array{default: mixed, children: mixed} $value */
        $parts = [$this->formatDefaultSeries($value['default'] ?? null)];

        foreach ($this->children($value['children'] ?? null) as $key => $child) {
            $parts[] = sprintf('над слоем «%s»: %s', $key, $this->formatDefaultSeries($child['default'] ?? null));
        }

        return implode('; ', $parts);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function children(mixed $children): array
    {
        if (!is_array($children)) {
            return [];
        }

        $result = [];
        foreach ($children as $key => $child) {
            if (is_array($child)) {
                $result[(string) $key] = $child;
            }
        }

        return $result;
    }

    private function formatDefaultSeries(mixed $series): string
    {
        return is_array($series) ? $this->seriesFormatter->formatSeries($series) : '';
    }
}
