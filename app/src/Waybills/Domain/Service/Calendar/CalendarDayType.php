<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Service\Calendar;

enum CalendarDayType: string
{
    case Workday = 'workday';
    case ShortenedWorkday = 'shortened_workday';
    case Weekend = 'weekend';
    case Holiday = 'holiday';
    case TransferWeekend = 'transfer_weekend';

    public function isDayOff(): bool
    {
        return match ($this) {
            self::Workday, self::ShortenedWorkday => false,
            self::Weekend, self::Holiday, self::TransferWeekend => true,
        };
    }
}
