<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Waybill\Fuel;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use PHPUnit\Framework\TestCase;

final class FuelTest extends TestCase
{
    public function test_actual_consumption_is_calculated_from_balance(): void
    {
        $fuel = new Fuel(30, 50, new Refuelings(new PositiveNumber(110.6)), 'АИ-95');

        self::assertSame(30, $fuel->initial());
        self::assertSame(50, $fuel->remaining());
        self::assertSame('АИ-95', $fuel->type());
        self::assertEqualsWithDelta(90.6, $fuel->consumed(), 0.000001);
    }

    public function test_incomplete_fuel_does_not_invent_refueling_or_remaining_values(): void
    {
        $fuel = new Fuel(30, null, null, 'АИ-95');
        self::assertNull($fuel->remainingOrNull());
        self::assertNull($fuel->refuelingsOrNull());

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('количество заправленного топлива');
        $fuel->consumed();
    }

    public function test_high_precision_initial_fuel_is_not_silently_rounded_for_consumption(): void
    {
        $fuel = new Fuel('30.00000000000000000001', 20, new Refuelings(), 'АИ-95');
        self::assertSame('30.00000000000000000001', $fuel->initial());

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('без потери данных');
        $fuel->consumed();
    }

    public function test_zero_balances_and_no_refuelings_are_valid(): void
    {
        $fuel = new Fuel(0, 0, new Refuelings(), 'АИ-95');
        self::assertSame(0, $fuel->consumed());
    }

    public function test_initial_balance_cannot_be_negative(): void
    {
        $this->expectException(AppException::class);
        new Fuel(-1, 0, new Refuelings(), 'АИ-95');
    }

    public function test_remaining_balance_cannot_be_negative(): void
    {
        $this->expectException(AppException::class);
        new Fuel(0, -1, new Refuelings(), 'АИ-95');
    }

    public function test_remaining_balance_may_exceed_available_fuel(): void
    {
        $fuel = new Fuel(10, 21, new Refuelings(new PositiveNumber(10)), 'АИ-95');

        self::assertSame(-1, $fuel->consumed());
    }

    public function test_decimal_rounding_is_close_to_zero_when_balances_match(): void
    {
        $fuel = new Fuel(0.3, 0.9, new Refuelings(new PositiveNumber(0.6)), 'АИ-95');
        self::assertEqualsWithDelta(0, $fuel->consumed(), 0.000000001);
    }

    public function test_small_negative_consumption_is_not_clamped(): void
    {
        $fuel = new Fuel(30, 30.0000000001, new Refuelings(), 'АИ-95');

        self::assertLessThan(0, $fuel->consumed());
    }

    public function test_initial_balance_cannot_be_infinite(): void
    {
        $this->expectException(AppException::class);
        new Fuel(INF, 0, new Refuelings(), 'АИ-95');
    }

    public function test_remaining_balance_cannot_be_nan(): void
    {
        $this->expectException(AppException::class);
        new Fuel(1, NAN, new Refuelings(), 'АИ-95');
    }

    public function test_available_fuel_cannot_overflow(): void
    {
        $this->expectException(AppException::class);
        new Fuel(1.0e308, 0, new Refuelings(new PositiveNumber(1.0e308)), 'АИ-95');
    }

    public function test_fuel_type_must_be_provided(): void
    {
        $this->expectException(AppException::class);
        new Fuel(0, 0, new Refuelings(), ' ');
    }
}
