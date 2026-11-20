<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Waybill\Fuel;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refueling;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use PHPUnit\Framework\TestCase;

final class RefuelingsTest extends TestCase
{
    public function test_empty_refuelings_have_zero_total(): void
    {
        $refuelings = new Refuelings();
        self::assertSame(0, $refuelings->total());
        self::assertSame([], $refuelings->entries());
    }

    public function test_total_can_be_entered_without_dated_entries(): void
    {
        $refuelings = new Refuelings(new PositiveNumber(110.6));
        self::assertSame(110.6, $refuelings->total());
        self::assertSame([], $refuelings->entries());
    }

    public function test_dated_entries_determine_total_even_when_total_was_provided(): void
    {
        $first = new Refueling(new \DateTimeImmutable('2026-10-05'), new PositiveNumber(35.15));
        $second = new Refueling(new \DateTimeImmutable('2026-10-12'), new PositiveNumber(30.45));
        $refuelings = new Refuelings(new PositiveNumber(100), $first, $second);

        self::assertEqualsWithDelta(65.6, $refuelings->total(), 0.000001);
        self::assertSame([$first, $second], $refuelings->entries());
        self::assertSame('2026-10-05', $first->date()->format('Y-m-d'));
        self::assertSame(35.15, $first->quantity()->value());
    }

    public function test_dated_refueling_cannot_have_an_infinite_amount(): void
    {
        $this->expectException(AppException::class);
        new Refueling(new \DateTimeImmutable('2026-10-05'), new PositiveNumber(INF));
    }

    public function test_total_cannot_be_infinite(): void
    {
        $this->expectException(AppException::class);
        new Refuelings(new PositiveNumber(INF));
    }
}
