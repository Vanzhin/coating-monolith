<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Service\Calendar;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class ProductionCalendar
{
    private const array SPECIAL_DAYS = [
        2026 => [
            '2026-01-01' => ['type' => 'holiday', 'name' => 'Новогодние каникулы'],
            '2026-01-02' => ['type' => 'holiday', 'name' => 'Новогодние каникулы'],
            '2026-01-03' => ['type' => 'holiday', 'name' => 'Новогодние каникулы'],
            '2026-01-04' => ['type' => 'holiday', 'name' => 'Новогодние каникулы'],
            '2026-01-05' => ['type' => 'holiday', 'name' => 'Новогодние каникулы'],
            '2026-01-06' => ['type' => 'holiday', 'name' => 'Новогодние каникулы'],
            '2026-01-07' => ['type' => 'holiday', 'name' => 'Рождество Христово'],
            '2026-01-08' => ['type' => 'holiday', 'name' => 'Новогодние каникулы'],
            '2026-01-09' => ['type' => 'transfer_weekend', 'name' => 'Выходной день (перенос с субботы 3 января)'],
            '2026-02-23' => ['type' => 'holiday', 'name' => 'День защитника Отечества'],
            '2026-03-08' => ['type' => 'holiday', 'name' => 'Международный женский день'],
            '2026-03-09' => ['type' => 'transfer_weekend', 'name' => 'Выходной день (перенос из-за совпадения праздника с воскресеньем)'],
            '2026-04-30' => ['type' => 'shortened_workday', 'name' => 'Предпраздничный рабочий день (сокращен на 1 час)'],
            '2026-05-01' => ['type' => 'holiday', 'name' => 'Праздник Весны и Труда'],
            '2026-05-08' => ['type' => 'shortened_workday', 'name' => 'Предпраздничный рабочий день (сокращен на 1 час)'],
            '2026-05-09' => ['type' => 'holiday', 'name' => 'День Победы'],
            '2026-05-11' => ['type' => 'transfer_weekend', 'name' => 'Выходной день (перенос из-за совпадения праздника с субботой)'],
            '2026-06-11' => ['type' => 'shortened_workday', 'name' => 'Предпраздничный рабочий день (сокращен на 1 час)'],
            '2026-06-12' => ['type' => 'holiday', 'name' => 'День России'],
            '2026-11-03' => ['type' => 'shortened_workday', 'name' => 'Предпраздничный рабочий день (сокращен на 1 час)'],
            '2026-11-04' => ['type' => 'holiday', 'name' => 'День народного единства'],
            '2026-12-31' => ['type' => 'transfer_weekend', 'name' => 'Выходной день (перенос с воскресенья 4 января)'],
        ],
        2027 => [
            '2027-01-01' => ['type' => 'holiday', 'name' => 'Новогодние каникулы'],
            '2027-01-02' => ['type' => 'holiday', 'name' => 'Новогодние каникулы (суббота)'],
            '2027-01-03' => ['type' => 'holiday', 'name' => 'Новогодние каникулы (воскресенье)'],
            '2027-01-04' => ['type' => 'holiday', 'name' => 'Новогодние каникулы'],
            '2027-01-05' => ['type' => 'holiday', 'name' => 'Новогодние каникулы'],
            '2027-01-06' => ['type' => 'holiday', 'name' => 'Новогодние каникулы'],
            '2027-01-07' => ['type' => 'holiday', 'name' => 'Рождество Христово'],
            '2027-01-08' => ['type' => 'holiday', 'name' => 'Новогодние каникулы'],
            '2027-02-20' => ['type' => 'shortened_workday', 'name' => 'Рабочая суббота (сокращена на 1 час, перенос на понедельник 22 февраля)'],
            '2027-02-22' => ['type' => 'transfer_weekend', 'name' => 'Выходной день (перенос с субботы 20 февраля)'],
            '2027-02-23' => ['type' => 'holiday', 'name' => 'День защитника Отечества'],
            '2027-03-08' => ['type' => 'holiday', 'name' => 'Международный женский день'],
            '2027-04-30' => ['type' => 'shortened_workday', 'name' => 'Предпраздничный рабочий день (сокращен на 1 час)'],
            '2027-05-01' => ['type' => 'holiday', 'name' => 'Праздник Весны и Труда (суббота)'],
            '2027-05-03' => ['type' => 'transfer_weekend', 'name' => 'Выходной день (перенос из-за праздника 1 мая)'],
            '2027-05-09' => ['type' => 'holiday', 'name' => 'День Победы (воскресенье)'],
            '2027-05-10' => ['type' => 'transfer_weekend', 'name' => 'Выходной день (перенос из-за праздника 9 мая)'],
            '2027-06-11' => ['type' => 'shortened_workday', 'name' => 'Предпраздничный рабочий день (сокращен на 1 час)'],
            '2027-06-12' => ['type' => 'holiday', 'name' => 'День России (суббота)'],
            '2027-06-14' => ['type' => 'transfer_weekend', 'name' => 'Выходной день (перенос из-за праздника 12 июня)'],
            '2027-11-03' => ['type' => 'shortened_workday', 'name' => 'Предпраздничный рабочий день (сокращен на 1 час)'],
            '2027-11-04' => ['type' => 'holiday', 'name' => 'День народного единства'],
            '2027-11-05' => ['type' => 'transfer_weekend', 'name' => 'Выходной день (перенос с субботы 2 января)'],
            '2027-12-31' => ['type' => 'transfer_weekend', 'name' => 'Выходной день (перенос с воскресенья 3 января)'],
        ],
    ];

    public function day(\DateTimeImmutable $date): CalendarDay
    {
        $date = $date->setTime(0, 0);
        $year = (int) $date->format('Y');
        $specialDay = self::SPECIAL_DAYS[$year][$date->format('Y-m-d')] ?? null;
        if (null !== $specialDay) {
            return new CalendarDay($date, CalendarDayType::from($specialDay['type']), $specialDay['name']);
        }

        if ((int) $date->format('N') >= 6) {
            return new CalendarDay($date, CalendarDayType::Weekend, 'Выходной день');
        }

        return new CalendarDay($date, CalendarDayType::Workday, 'Рабочий день');
    }

    public function isDayOff(\DateTimeImmutable $date): bool
    {
        return $this->day($date)->isDayOff();
    }

    /** @return list<CalendarDay> */
    public function daysOff(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_values(array_filter(
            $this->daysBetween($from, $to),
            static fn (CalendarDay $day): bool => $day->isDayOff(),
        ));
    }

    /** @return list<CalendarDay> */
    public function workingDays(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_values(array_filter(
            $this->daysBetween($from, $to),
            static fn (CalendarDay $day): bool => !$day->isDayOff(),
        ));
    }

    public function hasDataForYear(int $year): bool
    {
        return isset(self::SPECIAL_DAYS[$year]);
    }

    /** @return list<CalendarDay> */
    private function daysBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($from > $to) {
            throw new AppException('Начало периода календаря не может быть позже его окончания.');
        }

        $current = $from->setTime(0, 0);
        $last = $to->setTimezone($from->getTimezone())->setTime(0, 0);
        $days = [];
        while ($current <= $last) {
            $days[] = $this->day($current);
            $current = $current->modify('+1 day');
        }

        return $days;
    }
}
