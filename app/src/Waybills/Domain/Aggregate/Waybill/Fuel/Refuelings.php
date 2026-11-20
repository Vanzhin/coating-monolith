<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Waybill\Fuel;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;
use Brick\Math\BigDecimal;

final readonly class Refuelings
{
    private int|float $total;

    /** @var list<Refueling> */
    private array $entries;

    public function __construct(?PositiveNumber $total = null, Refueling ...$entries)
    {
        $this->entries = $entries;
        $computed = [] === $entries
            ? ($total?->value() ?? 0)
            : array_sum(array_map(static fn (Refueling $entry): int|float => $entry->quantity()->value(), $entries));

        if (!is_finite((float) $computed)) {
            throw new AppException('Общее количество заправленного топлива должно быть конечным числом.');
        }
        $this->total = $computed;
    }

    public function total(): int|float
    {
        return $this->total;
    }

    /** @return list<Refueling> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function equals(self $other): bool
    {
        if (!BigDecimal::of((string) $this->total)->isEqualTo((string) $other->total) || count($this->entries) !== count($other->entries)) {
            return false;
        }
        foreach ($this->entries as $index => $entry) {
            if (!$entry->equals($other->entries[$index])) {
                return false;
            }
        }

        return true;
    }
}
