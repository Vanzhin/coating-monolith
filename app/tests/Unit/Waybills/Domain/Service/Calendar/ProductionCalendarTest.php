<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Service\Calendar;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Service\Calendar\CalendarDay;
use App\Waybills\Domain\Service\Calendar\CalendarDayType;
use App\Waybills\Domain\Service\Calendar\ProductionCalendar;
use PHPUnit\Framework\TestCase;

final class ProductionCalendarTest extends TestCase
{
    private ProductionCalendar $calendar;

    protected function setUp(): void
    {
        $this->calendar = new ProductionCalendar();
    }

    public function test_shortened_working_saturday_is_not_a_day_off(): void
    {
        $day = $this->calendar->day(new \DateTimeImmutable('2027-02-20 15:30:00+03:00'));

        self::assertSame(CalendarDayType::ShortenedWorkday, $day->type);
        self::assertFalse($day->isDayOff());
        self::assertFalse($this->calendar->isDayOff($day->date));
        self::assertSame('2027-02-20 00:00:00+03:00', $day->date->format('Y-m-d H:i:sP'));
    }

    public function test_transfer_weekend_makes_monday_a_day_off(): void
    {
        $day = $this->calendar->day(new \DateTimeImmutable('2027-05-03'));

        self::assertSame(CalendarDayType::TransferWeekend, $day->type);
        self::assertSame('Выходной день (перенос из-за праздника 1 мая)', $day->name);
        self::assertTrue($day->isDayOff());
    }

    public function test_regular_weekend_and_workday_are_derived_from_day_of_week(): void
    {
        $weekend = $this->calendar->day(new \DateTimeImmutable('2027-05-15'));
        $workday = $this->calendar->day(new \DateTimeImmutable('2027-05-18'));

        self::assertSame(CalendarDayType::Weekend, $weekend->type);
        self::assertTrue($weekend->isDayOff());
        self::assertSame(CalendarDayType::Workday, $workday->type);
        self::assertFalse($workday->isDayOff());
    }

    public function test_holiday_is_day_off_and_shortened_workday_is_working(): void
    {
        self::assertTrue($this->calendar->isDayOff(new \DateTimeImmutable('2026-01-07')));
        self::assertFalse($this->calendar->isDayOff(new \DateTimeImmutable('2026-04-30')));
    }

    public function test_working_days_include_working_saturday_and_exclude_transferred_days_off(): void
    {
        $days = $this->calendar->workingDays(
            new \DateTimeImmutable('2027-02-19'),
            new \DateTimeImmutable('2027-02-23'),
        );

        self::assertSame(['2027-02-19', '2027-02-20'], self::dates($days));
    }

    public function test_days_off_include_regular_weekend_transfer_and_holiday(): void
    {
        $days = $this->calendar->daysOff(
            new \DateTimeImmutable('2027-02-19'),
            new \DateTimeImmutable('2027-02-23'),
        );

        self::assertSame(['2027-02-21', '2027-02-22', '2027-02-23'], self::dates($days));
    }

    public function test_period_includes_both_calendar_dates_and_normalizes_time(): void
    {
        $days = $this->calendar->workingDays(
            new \DateTimeImmutable('2027-05-04 08:15:00+03:00'),
            new \DateTimeImmutable('2027-05-05 18:45:00+03:00'),
        );

        self::assertSame(['2027-05-04', '2027-05-05'], self::dates($days));
        self::assertSame('00:00:00', $days[0]->date->format('H:i:s'));
        self::assertSame('00:00:00', $days[1]->date->format('H:i:s'));
    }

    public function test_period_can_cross_year_boundary(): void
    {
        $days = $this->calendar->daysOff(
            new \DateTimeImmutable('2026-12-30'),
            new \DateTimeImmutable('2027-01-02'),
        );

        self::assertSame(['2026-12-31', '2027-01-01', '2027-01-02'], self::dates($days));
    }

    public function test_reports_calendar_data_availability(): void
    {
        self::assertTrue($this->calendar->hasDataForYear(2026));
        self::assertTrue($this->calendar->hasDataForYear(2027));
        self::assertFalse($this->calendar->hasDataForYear(2028));
    }

    public function test_year_without_calendar_data_uses_regular_weekends(): void
    {
        $from = new \DateTimeImmutable('2028-01-07');
        $to = new \DateTimeImmutable('2028-01-10');

        self::assertSame(['2028-01-07', '2028-01-10'], self::dates($this->calendar->workingDays($from, $to)));
        self::assertSame(['2028-01-08', '2028-01-09'], self::dates($this->calendar->daysOff($from, $to)));
    }

    public function test_rejects_reversed_period(): void
    {
        $this->expectException(AppException::class);

        $this->calendar->workingDays(
            new \DateTimeImmutable('2027-05-02'),
            new \DateTimeImmutable('2027-05-01'),
        );
    }

    /**
     * @param list<CalendarDay> $days
     *
     * @return list<string>
     */
    private static function dates(array $days): array
    {
        return array_map(static fn (CalendarDay $day): string => $day->date->format('Y-m-d'), $days);
    }
}
