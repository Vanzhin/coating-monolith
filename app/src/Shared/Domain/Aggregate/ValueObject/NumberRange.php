<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

/**
 * @extends AbstractRange<int|float>
 *
 * @phpstan-consistent-constructor
 */
abstract readonly class NumberRange extends AbstractRange implements \JsonSerializable
{
    public function __construct(int|float $min, int|float $max)
    {
        parent::__construct($min, $max);
        $this->validate($min, $max);
    }

    protected function validate(int|float $min, int|float $max): void
    {
    }

    public function getMin(): float|int
    {
        return $this->start();
    }

    public function getMax(): float|int
    {
        return $this->end();
    }

    public function withMin(int|float $min): static
    {
        return new static($min, $this->getMax());
    }

    public function withMax(int|float $max): static
    {
        return new static($this->getMin(), $max);
    }

    public function mean(): int|float
    {
        return ($this->getMin() + $this->getMax()) / 2;
    }

    public function isWithin(int|float $value): bool
    {
        return $this->contains($value);
    }

    /** @return array{min: int|float, max: int|float} */
    public function jsonSerialize(): array
    {
        return ['min' => $this->getMin(), 'max' => $this->getMax()];
    }
}
