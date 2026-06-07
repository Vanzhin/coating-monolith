<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Domain\Aggregate\ValueObject\SeriesPoint;
use App\Shared\Infrastructure\Exception\AppException;

final readonly class TimeAtTemperature implements SeriesPoint
{
    public function __construct(
        public int $temperatureAt,
        public float $timeInMinutes,
        public bool $isCalculated = false,
    ) {
        if ($timeInMinutes < 0) {
            throw new AppException('Время не может быть отрицательным.');
        }
    }

    public function getKey(): int { return $this->temperatureAt; }

    public function getValue(): float { return $this->timeInMinutes; }

    public function isCalculated(): bool { return $this->isCalculated; }

    public function jsonSerialize(): array
    {
        return [
            'temperature_at' => $this->temperatureAt,
            'time_in_minutes' => $this->timeInMinutes,
            'is_calculated' => $this->isCalculated,
        ];
    }
}
