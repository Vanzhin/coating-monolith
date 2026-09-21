<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

/**
 * Толщина покрытия (ТСП): диапазон замеров [мин; макс] + среднее значение. Диапазон —
 * PositiveNumberRange (>0 + мин≤макс), среднее — PositiveNumber (измеренное, независимо от середины
 * диапазона). Инварианты — в переданных VO; здесь только композиция.
 */
final readonly class CoatingThickness implements \JsonSerializable
{
    public function __construct(
        private PositiveNumberRange $range,
        private PositiveNumber $mean,
    ) {
    }

    public function getMin(): int|float
    {
        return $this->range->getMin();
    }

    public function getMax(): int|float
    {
        return $this->range->getMax();
    }

    public function getMean(): int|float
    {
        return $this->mean->value();
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            new PositiveNumberRange((float) ($raw['min'] ?? 0), (float) ($raw['max'] ?? 0)),
            new PositiveNumber((float) ($raw['mean'] ?? 0)),
        );
    }

    /** @return array{min: int|float, max: int|float, mean: int|float} */
    public function jsonSerialize(): array
    {
        return ['min' => $this->getMin(), 'max' => $this->getMax(), 'mean' => $this->getMean()];
    }
}
