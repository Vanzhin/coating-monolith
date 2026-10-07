<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject\Instruction;

/**
 * Структурные поля инструктажа на факте — generic-набор `ключ→значение` по схеме журнала ({@see \App\Compliance\Domain\Type\JournalKind::fields()}).
 * Значение — строка или список строк (перечень локальных актов). Без бизнес-инвариантов: обязательность
 * полей проверяет подпись акта по схеме. Хранится в jsonb ({@see \App\Compliance\Infrastructure\Database\DBAL\InstructionDetailsType}).
 */
final readonly class InstructionDetails implements \JsonSerializable
{
    /** @param array<string, string|list<string>> $values */
    public function __construct(public array $values)
    {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $values = [];
        foreach ($raw as $key => $value) {
            if (is_array($value)) {
                $values[(string) $key] = array_values(array_map(static fn ($v): string => (string) $v, $value));
            } else {
                $values[(string) $key] = (string) $value;
            }
        }

        return new self($values);
    }

    /** @return string|list<string>|null */
    public function get(string $key): string|array|null
    {
        return $this->values[$key] ?? null;
    }

    public function has(string $key): bool
    {
        $value = $this->values[$key] ?? null;
        if (is_array($value)) {
            return [] !== $value;
        }

        return null !== $value && '' !== $value;
    }

    /** @return array<string, string|list<string>> */
    public function jsonSerialize(): array
    {
        return $this->values;
    }
}
