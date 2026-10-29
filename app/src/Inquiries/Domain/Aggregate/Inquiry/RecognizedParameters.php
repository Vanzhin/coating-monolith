<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Aggregate\Inquiry;

/**
 * Распознанные параметры запроса — непрозрачный key-value по осям, где у КАЖДОЙ оси есть провенанс и
 * оригинал робота (см. RecognizedValue). Домен ключи/типы не трактует (их смысл — у CoatingSystemsFilterInput).
 * toArray() отдаёт ПЛОСКИЕ эффективные значения (канон входа поиска), поэтому подбор ре-прогоняется без
 * трансформаций. jsonSerialize хранит {value, origin[, robotValue]} (jsonb); fromArray толерантен к старой
 * плоской форме (origin по умолчанию Model). Пагинация/сортировка — не оси, в провенансе не участвуют.
 */
final readonly class RecognizedParameters implements \JsonSerializable
{
    private const IGNORED_AXES = ['page', 'limit', 'sort'];

    /** @param array<string, RecognizedValue> $axes */
    private function __construct(private array $axes)
    {
    }

    /**
     * Все оси от модели/кэша при распознавании.
     *
     * @param array<string, mixed> $values плоские значения (вход фильтра)
     */
    public static function recognized(array $values, ParameterStrategy $origin = ParameterStrategy::Model): self
    {
        $axes = [];
        foreach ($values as $key => $value) {
            $axes[$key] = RecognizedValue::recognized($value, $origin);
        }

        return new self($axes);
    }

    /** @return array<string, mixed> плоские эффективные значения — прямой вход фильтра поиска */
    public function toArray(): array
    {
        return array_map(static fn (RecognizedValue $axis) => $axis->value, $this->axes);
    }

    public function originOf(string $key): ParameterStrategy
    {
        return isset($this->axes[$key]) ? $this->axes[$key]->origin : ParameterStrategy::Model;
    }

    /**
     * Оси, которые инженер поправил вручную. Пагинация/сортировка не считаются.
     *
     * @return list<string>
     */
    public function manuallyEditedKeys(): array
    {
        $keys = [];
        foreach ($this->axes as $key => $axis) {
            if ($axis->wasEdited() && !in_array($key, self::IGNORED_AXES, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Пары «робот → человек» по осям, которые инженер поправил — топливо градусника точности.
     * Пагинация/сортировка не оси, их не учитываем.
     *
     * @return array<string, array{robot: mixed, human: mixed}>
     */
    public function corrections(): array
    {
        $out = [];
        foreach ($this->axes as $key => $axis) {
            if (!$axis->wasEdited() || in_array($key, self::IGNORED_AXES, true)) {
                continue;
            }
            if ($this->isEmpty($axis->robotValue) && $this->isEmpty($axis->value)) {
                continue; // пустое→пустое (легаси-запись Manual с null) — правкой по смыслу не является
            }
            $out[$key] = ['robot' => $axis->robotValue, 'human' => $axis->value];
        }

        return $out;
    }

    /**
     * По каждой оси (кроме пагинации/сортировки) — что выдал робот и что в итоге эффективно. Для
     * неправленых осей robot == human. Полный срез для измерения точности (в отличие от corrections(),
     * который отдаёт только правленые оси).
     *
     * @return array<string, array{robot: mixed, human: mixed}>
     */
    public function axisComparison(): array
    {
        $out = [];
        foreach ($this->axes as $key => $axis) {
            if (!in_array($key, self::IGNORED_AXES, true)) {
                $out[$key] = ['robot' => $axis->robotValue, 'human' => $axis->value];
            }
        }

        return $out;
    }

    /**
     * Ручная правка инженера: новый набор эффективных значений. Реально изменившиеся оси помечаем Manual
     * (оригинал робота сохраняется внутри RecognizedValue), прочие сохраняют прежнее состояние. Пагинация/
     * сортировка — всегда машинные, осью не становятся.
     *
     * @param array<string, mixed> $newValues
     */
    public function withManualEdits(array $newValues): self
    {
        $axes = [];
        foreach ($newValues as $key => $value) {
            $prev = $this->axes[$key] ?? null;
            if (in_array($key, self::IGNORED_AXES, true)) {
                $axes[$key] = RecognizedValue::recognized($value, null !== $prev ? $prev->origin : ParameterStrategy::Model);
            } elseif (null !== $prev && $this->same($prev->value, $value)) {
                $axes[$key] = $prev; // ось не менялась — провенанс и оригинал робота как были
            } elseif (null !== $prev) {
                $axes[$key] = $prev->editedByHuman($value); // перекрыли — робот сохранён внутри
            } else {
                $axes[$key] = RecognizedValue::recognized(null)->editedByHuman($value); // человек задал ось, которой у робота не было
            }
        }

        return new self($axes);
    }

    /** @return array<string, array{value: mixed, origin: string, robotValue?: mixed}> */
    public function jsonSerialize(): array
    {
        $out = [];
        foreach ($this->axes as $key => $axis) {
            $entry = ['value' => $axis->value, 'origin' => $axis->origin->value];
            if ($axis->wasEdited()) {
                $entry['robotValue'] = $axis->robotValue; // оригинал робота — только у перекрытых осей
            }
            $out[$key] = $entry;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $axes = [];
        foreach ($data as $key => $raw) {
            if (is_array($raw) && array_key_exists('value', $raw) && array_key_exists('origin', $raw)) {
                $origin = (is_string($raw['origin']) ? ParameterStrategy::tryFrom($raw['origin']) : null) ?? ParameterStrategy::Model;
                $axes[$key] = ParameterStrategy::Manual === $origin
                    ? RecognizedValue::recognized($raw['robotValue'] ?? null)->editedByHuman($raw['value'])
                    : RecognizedValue::recognized($raw['value'], $origin);
            } else {
                $axes[$key] = RecognizedValue::recognized($raw); // старая плоская форма → origin Model
            }
        }

        return new self($axes);
    }

    private function isEmpty(mixed $value): bool
    {
        return null === $value || [] === $value || '' === $value;
    }

    private function same(mixed $a, mixed $b): bool
    {
        return $this->normalize($a) === $this->normalize($b);
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            sort($value);
        }

        return $value;
    }
}
