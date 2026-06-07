<?php
declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\ValueObject\Fixtures;

use App\Shared\Domain\Aggregate\ValueObject\SeriesPoint;

final readonly class IntSeriesPoint implements SeriesPoint
{
    public function __construct(
        public int $key,
        public int $value,
        public bool $isCalculated = false,
    ) {}

    public function getKey(): int { return $this->key; }

    public function getValue(): int { return $this->value; }

    public function isCalculated(): bool { return $this->isCalculated; }

    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'is_calculated' => $this->isCalculated,
        ];
    }
}
