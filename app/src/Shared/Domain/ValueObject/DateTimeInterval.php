<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Интервал дат {с, по}. Хотя бы одна граница обязательна (открытый с одной стороны — допустим).
 * Если заданы обе — «с» не позже «по». Хранится как JSON {from,to} (ISO 8601). Инвариант — в конструкторе.
 */
final readonly class DateTimeInterval implements \JsonSerializable
{
    public function __construct(
        private ?\DateTimeImmutable $from = null,
        private ?\DateTimeImmutable $to = null,
    ) {
        if (null === $from && null === $to) {
            throw new AppException('Период: укажите хотя бы одну дату (с или по).');
        }
        if (null !== $from && null !== $to && $from > $to) {
            throw new AppException('Период: дата «с» не может быть позже даты «по».');
        }
    }

    public function getFrom(): ?\DateTimeImmutable
    {
        return $this->from;
    }

    public function getTo(): ?\DateTimeImmutable
    {
        return $this->to;
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $parse = static fn (mixed $v): ?\DateTimeImmutable => is_string($v) && '' !== $v ? new \DateTimeImmutable($v) : null;

        return new self($parse($raw['from'] ?? null), $parse($raw['to'] ?? null));
    }

    /** @return array{from: ?string, to: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'from' => $this->from?->format('Y-m-d\TH:i:s'),
            'to' => $this->to?->format('Y-m-d\TH:i:s'),
        ];
    }
}
