<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

/**
 * Длительность как значение — единственный владелец конверсии единиц (дни/часы/минуты ↔ минуты)
 * и канонических множителей. Заводится, чтобы конверсия не жила магическими числами в
 * инфраструктуре (форм-мэппер длительностей, list-мэпперы интервалов перекрытия). Домен хранит
 * длительности в минутах. Нейтральный носитель: без инварианта «> 0» — положительность
 * проверяется там, где она осмысленна (TimeAtTemperature).
 */
final readonly class Duration
{
    public const MINUTES_PER_HOUR = 60;
    public const MINUTES_PER_DAY = 24 * 60;

    private function __construct(public int $minutes)
    {
    }

    public static function ofMinutes(int $minutes): self
    {
        return new self($minutes);
    }

    public static function fromParts(int $days, int $hours, int $minutes): self
    {
        return new self($days * self::MINUTES_PER_DAY + $hours * self::MINUTES_PER_HOUR + $minutes);
    }

    public function minutes(): int
    {
        return $this->minutes;
    }

    /**
     * @return array{days: int, hours: int, minutes: int}
     */
    public function toParts(): array
    {
        $days = intdiv($this->minutes, self::MINUTES_PER_DAY);
        $rem = $this->minutes - $days * self::MINUTES_PER_DAY;
        $hours = intdiv($rem, self::MINUTES_PER_HOUR);

        return ['days' => $days, 'hours' => $hours, 'minutes' => $rem - $hours * self::MINUTES_PER_HOUR];
    }
}
