<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Service\Calendar;

final readonly class CalendarDay
{
    public function __construct(
        public \DateTimeImmutable $date,
        public CalendarDayType $type,
        public string $name,
    ) {
    }

    public function isDayOff(): bool
    {
        return $this->type->isDayOff();
    }
}
