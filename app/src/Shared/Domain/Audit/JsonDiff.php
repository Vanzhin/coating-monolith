<?php

declare(strict_types=1);

namespace App\Shared\Domain\Audit;

/**
 * Дженерик структурный дифф двух значений → список изменений (set/add/remove) по путям.
 * VO нормализуется полным round-trip json_encode/decode. Карты идут вглубь до скаляра
 * (add/remove ключей-веток). Списки МАП, у которых есть общий ключ идентичности (см.
 * findIdentityKey), сопоставляются по значению этого ключа — правка одного элемента
 * превращается в точечный set по под-пути, а не в remove+add всего элемента. Списки
 * скаляров и списки мап без такого ключа — по глубокому равенству элементов (fallback):
 * только в old → remove, только в new → add (правка элемента = remove+add). Равные
 * значения → пусто (гасит ложную грязь Doctrine: сравнение VO по ссылке).
 */
final class JsonDiff
{
    /** @return list<FieldChange> */
    public function diff(mixed $old, mixed $new, string $path = ''): array
    {
        $old = $this->normalize($old);
        $new = $this->normalize($new);

        if ($old === $new) {
            return [];
        }
        if (!is_array($old) || !is_array($new)) {
            return [FieldChange::set($path, $old, $new)]; // скаляр/смена типа (напр. null→дерево)
        }

        // Empty arrays defer to the other side's shape (both-empty already short-circuited above)
        $oldIsList = [] === $old ? array_is_list($new) : array_is_list($old);
        $newIsList = [] === $new ? array_is_list($old) : array_is_list($new);

        if ($oldIsList !== $newIsList) {
            return [FieldChange::set($path, $old, $new)];
        }

        return $oldIsList
            ? $this->diffList($old, $new, $path)
            : $this->diffMap($old, $new, $path);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     *
     * @return list<FieldChange>
     */
    private function diffMap(array $old, array $new, string $path): array
    {
        $changes = [];
        foreach (array_keys($old + $new) as $key) {
            $sub = '' === $path ? (string) $key : $path.'.'.$key;
            $inOld = array_key_exists($key, $old);
            $inNew = array_key_exists($key, $new);
            if ($inOld && $inNew) {
                $changes = [...$changes, ...$this->diff($old[$key], $new[$key], $sub)];
            } elseif ($inNew) {
                $changes[] = FieldChange::add($sub, $new[$key]); // добавили ветку целиком
            } else {
                $changes[] = FieldChange::remove($sub, $old[$key]); // удалили ветку целиком
            }
        }

        return $changes;
    }

    /**
     * Списки: если у элементов есть общий ключ идентичности — сопоставляем по нему
     * (точечный дифф), иначе — fallback к сравнению по глубокому равенству.
     *
     * @param list<mixed> $old
     * @param list<mixed> $new
     *
     * @return list<FieldChange>
     */
    private function diffList(array $old, array $new, string $path): array
    {
        $identityKey = $this->findIdentityKey($old, $new);

        return null !== $identityKey
            ? $this->diffListByIdentityKey($old, $new, $path, $identityKey)
            : $this->diffListByEquality($old, $new, $path);
    }

    /**
     * Ищет ключ идентичности для списка мап: ключ, присутствующий в КАЖДОМ элементе old
     * И new, чьё значение — ненулевой скаляр и уникально внутри old и внутри new по
     * отдельности. Берём первый подходящий ключ в порядке ключей первого элемента old
     * (детерминированно). Нет кандидата (список скаляров, список мап без общего уникального
     * поля, пустой список) → null, вызывающий код уходит в fallback по равенству.
     *
     * @param list<mixed> $old
     * @param list<mixed> $new
     */
    private function findIdentityKey(array $old, array $new): int|string|null
    {
        if ([] === $old || [] === $new) {
            return null;
        }
        foreach ([...$old, ...$new] as $item) {
            if (!is_array($item)) {
                return null; // список скаляров (или смешанный) — ключа идентичности не бывает
            }
        }

        foreach (array_keys($old[0]) as $candidate) {
            if ($this->isUniqueScalarKey($candidate, $old) && $this->isUniqueScalarKey($candidate, $new)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Проверяет, что $key есть во всех элементах $items, его значение — ненулевой
     * скаляр, и эти значения не повторяются (годится как ключ идентичности).
     *
     * @param list<array<int|string, mixed>> $items
     */
    private function isUniqueScalarKey(int|string $key, array $items): bool
    {
        $seenMarkers = [];
        foreach ($items as $item) {
            if (!array_key_exists($key, $item) || !is_scalar($item[$key])) {
                return false;
            }
            $marker = $this->scalarMarker($item[$key]); // тип+значение — bool(1) не путаем с int(1)
            if (isset($seenMarkers[$marker])) {
                return false; // дубликат значения — не годится как идентификатор
            }
            $seenMarkers[$marker] = true;
        }

        return true;
    }

    private function scalarMarker(bool|int|float|string $value): string
    {
        return get_debug_type($value).':'.$value;
    }

    /**
     * Сопоставляет элементы по значению ключа идентичности $key: обе стороны есть →
     * рекурсивный diff под-пути (изменившееся поле точки даёт точечный set, ключ
     * идентичности не меняется и в дифф не попадает); только new → add; только old → remove.
     * Порядок обхода: сначала значения ключа из old (в порядке old), затем новые из new.
     *
     * @param list<array<int|string, mixed>> $old
     * @param list<array<int|string, mixed>> $new
     *
     * @return list<FieldChange>
     */
    private function diffListByIdentityKey(array $old, array $new, string $path, int|string $key): array
    {
        $oldByMarker = [];
        foreach ($old as $item) {
            $oldByMarker[$this->scalarMarker($item[$key])] = $item;
        }
        $newByMarker = [];
        foreach ($new as $item) {
            $newByMarker[$this->scalarMarker($item[$key])] = $item;
        }

        $order = array_keys($oldByMarker);
        foreach (array_keys($newByMarker) as $marker) {
            if (!array_key_exists($marker, $oldByMarker)) {
                $order[] = $marker;
            }
        }

        $changes = [];
        foreach ($order as $marker) {
            $inOld = array_key_exists($marker, $oldByMarker);
            $inNew = array_key_exists($marker, $newByMarker);
            $identityValue = $inOld ? $oldByMarker[$marker][$key] : $newByMarker[$marker][$key];
            $sub = $path.'.'.$identityValue;

            if ($inOld && $inNew) {
                $changes = [...$changes, ...$this->diff($oldByMarker[$marker], $newByMarker[$marker], $sub)];
            } elseif ($inNew) {
                $changes[] = FieldChange::add($sub, $newByMarker[$marker]); // новый элемент целиком
            } else {
                $changes[] = FieldChange::remove($sub, $oldByMarker[$marker]); // убранный элемент целиком
            }
        }

        return $changes;
    }

    /**
     * Списки без ключа идентичности — по глубокому равенству элементов (как раньше).
     *
     * @param list<mixed> $old
     * @param list<mixed> $new
     *
     * @return list<FieldChange>
     */
    private function diffListByEquality(array $old, array $new, string $path): array
    {
        $newRemaining = $new;
        $changes = [];
        foreach ($old as $item) {
            $idx = $this->indexOf($item, $newRemaining);
            if (null === $idx) {
                $changes[] = FieldChange::remove($path, $item);
            } else {
                unset($newRemaining[$idx]); // погасили совпадение (мультимножество)
            }
        }
        foreach ($newRemaining as $item) {
            $changes[] = FieldChange::add($path, $item);
        }

        return $changes;
    }

    /** @param list<mixed> $haystack */
    private function indexOf(mixed $needle, array $haystack): ?int
    {
        foreach ($haystack as $i => $candidate) {
            if ($needle === $candidate) { // === на массивах — глубокое сравнение (ключи/значения/порядок)
                return $i;
            }
        }

        return null;
    }

    private function normalize(mixed $value): mixed
    {
        if (null === $value || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }

        return json_decode(json_encode($value, \JSON_THROW_ON_ERROR), true);
    }
}
