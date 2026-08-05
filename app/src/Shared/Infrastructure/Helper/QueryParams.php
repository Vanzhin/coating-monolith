<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Helper;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\RangeFilter;
use Symfony\Component\HttpFoundation\Request;

/**
 * Единая идиома чтения query-параметров для мап-еров списков. Раньше каждый
 * list-экшен парсил query по-своему (nullableInt vs readRange) — тут одно место.
 * Только чтение/shape: приведение типов и построение RangeFilter из голых
 * границ. Доменные инварианты (границы фасета) — забота самого RangeFilter.
 */
final class QueryParams
{
    public function nullableInt(Request $request, string $key): ?int
    {
        $raw = $request->query->get($key);
        if (null === $raw || '' === trim((string) $raw)) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * @param callable(string): bool|null $isValid
     */
    public function stringCollection(
        Request $request,
        string $key,
        ?callable $isValid = null,
        bool $unique = false,
    ): StringCollection {
        $values = array_values(array_filter(
            $request->query->all($key),
            static fn (mixed $v): bool => is_string($v),
        ));

        if (null !== $isValid) {
            $values = array_values(array_filter($values, $isValid));
        }
        if ($unique) {
            $values = array_values(array_unique($values));
        }

        return new StringCollection(...$values);
    }

    public function intRange(
        Request $request,
        string $fromKey,
        string $toKey,
        int $multiplier = 1,
        bool $dropInverted = true,
    ): ?RangeFilter {
        $from = $this->nullableInt($request, $fromKey);
        $to = $this->nullableInt($request, $toKey);
        $from = null !== $from ? $from * $multiplier : null;
        $to = null !== $to ? $to * $multiplier : null;

        // dropInverted=true: инвертированный диапазон тихо роняем (список систем).
        // dropInverted=false: делегируем в RangeFilter — он кинет AppException на
        // from>to (список покрытий показывает ошибку в форме, а не молчит).
        if ($dropInverted && null !== $from && null !== $to && $from > $to) {
            return null;
        }

        return RangeFilter::tryFromNullable($from, $to);
    }
}
