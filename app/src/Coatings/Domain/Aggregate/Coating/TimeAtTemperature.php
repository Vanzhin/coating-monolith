<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Infrastructure\Exception\AppException;
use Carbon\CarbonInterval;
use JsonSerializable;

/**
 * Точка серии «температура → длительность».
 *
 * Семантика timeInMinutes:
 *  - > 0 → реальная длительность в минутах.
 *  - 0   → «без ограничения» (явно введено производителем).
 *  - null → «нет данных» (производитель не указал).
 *
 * Конструктор отвергает только отрицательные значения. null/0 — валидны.
 */
final readonly class TimeAtTemperature implements JsonSerializable
{
    public function __construct(
        public int $temperatureAt,
        public ?int $timeInMinutes,
        public bool $isCalculated = false,
    ) {
        if ($timeInMinutes !== null && $timeInMinutes < 0) {
            throw new AppException(sprintf(
                'Длительность при +%d °C не может быть отрицательной.',
                $temperatureAt,
            ));
        }
    }

    public function getInterval(): ?CarbonInterval
    {
        if ($this->timeInMinutes === null || $this->timeInMinutes === 0) {
            return null;
        }
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
