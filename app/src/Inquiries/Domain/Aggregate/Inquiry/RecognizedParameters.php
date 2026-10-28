<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Aggregate\Inquiry;

/**
 * Распознанные параметры запроса — НЕПРОЗРАЧНЫЙ key-value + провенанс КАЖДОГО значения (origin: кто
 * дал — модель/кэш/инженер). Домен ключи не трактует (их смысл — у типа запроса). toArray() отдаёт
 * ПЛОСКИЕ значения (канон входа поиска — CoatingSystemsFilterInput), поэтому подбор ре-прогоняется
 * без трансформаций. jsonSerialize хранит структуру {value, origin} (jsonb); fromArray толерантен к
 * старой плоской форме (origin по умолчанию Model). Пагинация/сортировка — не оси, в провенансе не участвуют.
 */
final readonly class RecognizedParameters implements \JsonSerializable
{
    private const IGNORED_AXES = ['page', 'limit', 'sort'];

    /**
     * @param array<string, mixed>             $values  ключ → значение (плоско)
     * @param array<string, ParameterStrategy> $origins ключ → провенанс; отсутствует → Model
     */
    public function __construct(
        private array $values,
        private array $origins = [],
    ) {
    }

    /** @return array<string, mixed> плоские значения — прямой вход фильтра поиска */
    public function toArray(): array
    {
        return $this->values;
    }

    public function originOf(string $key): ParameterStrategy
    {
        return $this->origins[$key] ?? ParameterStrategy::Model;
    }

    /**
     * Оси, которые инженер поправил вручную (origin = Manual). Пагинация/сортировка не считаются.
     *
     * @return list<string>
     */
    public function manuallyEditedKeys(): array
    {
        $keys = [];
        foreach ($this->origins as $key => $origin) {
            if (ParameterStrategy::Manual === $origin && !in_array($key, self::IGNORED_AXES, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Ручная правка инженера: новый набор значений; реально изменившиеся оси помечаем origin = Manual,
     * прочие сохраняют прежний провенанс. Массивы сравниваем как множества (порядок не важен).
     *
     * @param array<string, mixed> $newValues
     */
    public function withManualEdits(array $newValues): self
    {
        $origins = array_intersect_key($this->origins, $newValues); // убрать провенанс исчезнувших полей
        foreach (array_keys(array_diff_key($this->values + $newValues, array_flip(self::IGNORED_AXES))) as $key) {
            if ($this->normalize($this->values[$key] ?? null) !== $this->normalize($newValues[$key] ?? null)) {
                $origins[$key] = ParameterStrategy::Manual;
            }
        }

        return new self($newValues, $origins);
    }

    /** @return array<string, array{value: mixed, origin: string}> */
    public function jsonSerialize(): array
    {
        $out = [];
        foreach ($this->values as $key => $value) {
            $out[$key] = ['value' => $value, 'origin' => $this->originOf($key)->value];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $values = [];
        $origins = [];
        foreach ($data as $key => $raw) {
            if (is_array($raw) && array_key_exists('value', $raw) && array_key_exists('origin', $raw)) {
                $values[$key] = $raw['value'];
                $origins[$key] = (is_string($raw['origin']) ? ParameterStrategy::tryFrom($raw['origin']) : null) ?? ParameterStrategy::Model;
            } else {
                $values[$key] = $raw; // старая плоская форма → origin Model по умолчанию
            }
        }

        return new self($values, $origins);
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            sort($value);
        }

        return $value;
    }
}
