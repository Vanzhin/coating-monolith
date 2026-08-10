<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Infrastructure\Exception\AppException;
use Carbon\CarbonInterval;

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
final readonly class TimeAtTemperature implements \JsonSerializable
{
    public function __construct(
        public int $temperatureAt,
        public ?int $timeInMinutes,
        public bool $isCalculated = false,
    ) {
        if (null !== $timeInMinutes && $timeInMinutes < 0) {
            throw new AppException(sprintf('Длительность при +%d °C не может быть отрицательной.', $temperatureAt));
        }
    }

    public function getInterval(): ?CarbonInterval
    {
        if (null === $this->timeInMinutes || 0 === $this->timeInMinutes) {
            return null;
        }

        return CarbonInterval::minutes($this->timeInMinutes);
    }

    /**
     * Явно заданная (не интерполированная) точка с конкретной положительной длительностью.
     * Противоположность — легковесные состояния: unknown (null), unlimited (0), вычисленная (isCalculated).
     */
    public function isExplicitPositiveDuration(): bool
    {
        return !$this->isCalculated
            && null !== $this->timeInMinutes
            && $this->timeInMinutes > 0;
    }

    /**
     * Есть ли у точки положительная длительность (в т.ч. вычисленная интерполяцией).
     * В отличие от isExplicitPositiveDuration() не смотрит на isCalculated: unlimited (0)
     * и unknown (null) — false, любая положительная длительность — true.
     */
    public function hasPositiveDuration(): bool
    {
        return null !== $this->timeInMinutes && $this->timeInMinutes > 0;
    }

    /**
     * @return array{temperature_at: int|float, time_in_minutes: int|null, is_calculated: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'temperature_at' => $this->temperatureAt,
            'time_in_minutes' => $this->timeInMinutes,
            'is_calculated' => $this->isCalculated,
        ];
    }
}
