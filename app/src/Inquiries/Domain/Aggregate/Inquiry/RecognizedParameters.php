<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Aggregate\Inquiry;

/**
 * Распознанные параметры запроса — НЕПРОЗРАЧНЫЙ key-value. Домен их не трактует и не знает, что
 * внутри: смысл ключей принадлежит типу запроса (Application). Физически == сериализованный вход
 * поиска (для подбора систем — CoatingSystemsFilterInput как массив), поэтому напрямую
 * ре-прогоняется поиском без трансформаций. Хранится jsonb.
 */
final readonly class RecognizedParameters implements \JsonSerializable
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->values;
    }
}
