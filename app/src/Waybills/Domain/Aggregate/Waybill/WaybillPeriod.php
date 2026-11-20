<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Waybill;

use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Exception\AppException;

final readonly class WaybillPeriod
{
    private \DateTimeImmutable $from;
    private \DateTimeImmutable $to;

    public function __construct(DateTimeInterval $interval)
    {
        $from = $interval->getFrom();
        $to = $interval->getTo();
        if (null === $from || null === $to) {
            throw new AppException('Укажите даты начала и окончания периода путевого листа.');
        }
        $this->from = $from;
        $this->to = $to;
    }

    public static function fromDates(\DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        return new self(new DateTimeInterval($from, $to));
    }

    public function from(): \DateTimeImmutable
    {
        return $this->from;
    }

    public function to(): \DateTimeImmutable
    {
        return $this->to;
    }

    public function contains(\DateTimeImmutable $date): bool
    {
        return $date >= $this->from && $date <= $this->to;
    }

    public function overlaps(self $other): bool
    {
        return $this->from->format('Y-m-d') <= $other->to->format('Y-m-d')
            && $other->from->format('Y-m-d') <= $this->to->format('Y-m-d');
    }

    public function assertDoesNotOverlap(self ...$periods): void
    {
        foreach ($periods as $period) {
            if ($this->overlaps($period)) {
                throw new AppException('Период путевого листа пересекается с другим листом.');
            }
        }
    }

    public function assertFollows(self ...$periods): void
    {
        foreach ($periods as $period) {
            if ($this->from->format('Y-m-d') <= $period->to->format('Y-m-d')) {
                throw new AppException('Новый путевой лист должен начинаться после последнего утверждённого листа автомобиля.');
            }
        }
    }
}
