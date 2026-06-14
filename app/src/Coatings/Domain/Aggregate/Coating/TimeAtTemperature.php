<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Infrastructure\Exception\AppException;
use Carbon\CarbonInterval;
use JsonSerializable;

final readonly class TimeAtTemperature implements JsonSerializable
{
    public function __construct(
        public int $temperatureAt,
        public int $timeInMinutes,
        public bool $isCalculated = false,
    ) {
        if ($timeInMinutes < 0) {
            throw new AppException('Время не может быть отрицательным.');
        }
    }

    public function getInterval(): CarbonInterval
    {
        return CarbonInterval::minutes($this->timeInMinutes);
    }

    public function jsonSerialize(): array
    {
        return [
            'temperature_at' => $this->temperatureAt,
            'time_in_minutes' => $this->timeInMinutes,
            'is_calculated' => $this->isCalculated,
        ];
    }
}
