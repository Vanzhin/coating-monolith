<?php
declare(strict_types=1);
namespace App\Shared\Domain\Audit;

/**
 * Дженерик структурный дифф двух значений → список изменений (set/add/remove) по путям.
 * VO нормализуется полным round-trip json_encode/decode. Карты идут вглубь до скаляра
 * (add/remove ключей-веток). Списки сопоставляются по глубокому равенству элементов:
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

        // Empty arrays are compatible with both lists and maps — treat as same type as counterpart
        $oldIsList = !empty($old) && array_is_list($old);
        $newIsList = !empty($new) && array_is_list($new);

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
     * Списки — по глубокому равенству элементов (без ключа идентичности).
     * @param list<mixed> $old
     * @param list<mixed> $new
     * @return list<FieldChange>
     */
    private function diffList(array $old, array $new, string $path): array
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
