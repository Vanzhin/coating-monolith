<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Infrastructure\Database\DBAL;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refueling;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use App\Waybills\Infrastructure\Database\DBAL\WaybillFuelType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WaybillFuelTypeTest extends TestCase
{
    public function test_refueling_total_can_be_saved_before_final_fuel_remaining(): void
    {
        $type = new WaybillFuelType();
        $platform = new PostgreSQLPlatform();
        $fuel = new Fuel(30, null, new Refuelings(new PositiveNumber(12)), 'АИ-95');

        $loaded = $type->convertToPHPValue($type->convertToDatabaseValue($fuel, $platform), $platform);

        self::assertNotNull($loaded);
        self::assertNull($loaded->remainingOrNull());
        self::assertSame(12, $loaded->refuelings()->total());
    }

    public function test_remaining_can_be_saved_before_refueling_total(): void
    {
        $type = new WaybillFuelType();
        $platform = new PostgreSQLPlatform();
        $fuel = new Fuel(30, 25, null, 'АИ-95');
        $loaded = $type->convertToPHPValue($type->convertToDatabaseValue($fuel, $platform), $platform);

        self::assertNotNull($loaded);
        self::assertSame(25, $loaded->remaining());
        self::assertNull($loaded->refuelingsOrNull());
    }

    public function test_high_precision_initial_balance_is_not_rounded(): void
    {
        $type = new WaybillFuelType();
        $platform = new PostgreSQLPlatform();
        $fuel = new Fuel('30.00000000000000000001', null, new Refuelings(new PositiveNumber(12)), 'АИ-95');
        $loaded = $type->convertToPHPValue($type->convertToDatabaseValue($fuel, $platform), $platform);

        self::assertNotNull($loaded);
        self::assertSame('30.00000000000000000001', $loaded->initial());
        self::assertNull($loaded->remainingOrNull());
        self::assertSame(12, $loaded->refuelings()->total());
    }

    public function test_null_is_preserved(): void
    {
        $type = new WaybillFuelType();
        $platform = new PostgreSQLPlatform();

        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
    }

    public function test_round_trip_preserves_order_dates_zones_and_quantities(): void
    {
        $first = new \DateTimeImmutable('2026-07-01 12:30:45.123456', new \DateTimeZone('Europe/Berlin'));
        $second = new \DateTimeImmutable('2026-01-01 01:02:03.000001', new \DateTimeZone('+05:45'));
        $fuel = new Fuel(50.5, 12, new Refuelings(null,
            new Refueling($first, new PositiveNumber(10.25)),
            new Refueling($second, new PositiveNumber(20)),
        ), 'ДТ');

        $restored = $this->roundTrip($fuel);

        self::assertSame(50.5, $restored->initial());
        self::assertEquals(12, $restored->remaining());
        self::assertSame('ДТ', $restored->type());
        self::assertEquals(30.25, $restored->refuelings()->total());
        $entries = $restored->refuelings()->entries();
        self::assertCount(2, $entries);
        foreach ([$first, $second] as $index => $expected) {
            self::assertSame($expected->format('Y-m-d H:i:s.uP'), $entries[$index]->date()->format('Y-m-d H:i:s.uP'));
            self::assertSame($expected->format('U.u'), $entries[$index]->date()->format('U.u'));
            self::assertSame($expected->getTimezone()->getName(), $entries[$index]->date()->getTimezone()->getName());
        }
        self::assertEquals(10.25, $entries[0]->quantity()->value());
        self::assertEquals(20, $entries[1]->quantity()->value());
    }

    public function test_round_trip_preserves_undated_total_without_inventing_entries(): void
    {
        $restored = $this->roundTrip(new Fuel(4, 3, new Refuelings(new PositiveNumber(25.5)), 'АИ-95'));

        self::assertEquals(25.5, $restored->refuelings()->total());
        self::assertSame([], $restored->refuelings()->entries());
    }

    public function test_round_trip_preserves_zero_values(): void
    {
        $restored = $this->roundTrip(new Fuel(0, 0, new Refuelings(), 'ДТ'));

        self::assertEquals(0, $restored->initial());
        self::assertEquals(0, $restored->remaining());
        self::assertEquals(0, $restored->refuelings()->total());
        self::assertSame([], $restored->refuelings()->entries());
    }

    public function test_round_trip_preserves_computed_floating_point_total(): void
    {
        $date = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        $refuelings = new Refuelings(null,
            new Refueling($date, new PositiveNumber(0.1)),
            new Refueling($date, new PositiveNumber(0.2)),
        );

        $restored = $this->roundTrip(new Fuel(0, 0, $refuelings, 'ДТ'));

        self::assertSame($refuelings->total(), $restored->refuelings()->total());
    }

    #[DataProvider('invalidShapes')]
    public function test_rejects_invalid_structure(string $json): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new WaybillFuelType())->convertToPHPValue($json, new PostgreSQLPlatform());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidShapes(): iterable
    {
        yield 'scalar' => ['42'];
        yield 'missing fields' => ['{}'];
        yield 'invalid numeric string' => ['{"initial":"not-a-number","remaining":0,"type":"ДТ","refuelings":{"total":0,"entries":[]}}'];
        yield 'missing total' => ['{"initial":1,"remaining":0,"type":"ДТ","refuelings":{"entries":[]}}'];
        yield 'negative total' => ['{"initial":1,"remaining":0,"type":"ДТ","refuelings":{"total":-1,"entries":[]}}'];
        yield 'entries not a list' => ['{"initial":1,"remaining":0,"type":"ДТ","refuelings":{"total":0,"entries":{"a":1}}}'];
        yield 'invalid entry' => ['{"initial":1,"remaining":0,"type":"ДТ","refuelings":{"total":1,"entries":[{"date":1,"quantity":1}]}}'];
        yield 'invalid date' => ['{"initial":1,"remaining":0,"type":"ДТ","refuelings":{"total":1,"entries":[{"date":"2026-01-01","quantity":1}]}}'];
    }

    #[DataProvider('mismatchedTotals')]
    public function test_rejects_total_that_differs_from_dated_entries(int $total): void
    {
        $json = '{"initial":1,"remaining":0,"type":"ДТ","refuelings":{"total":'.$total.',"entries":[{"date":"2026-01-01T12:00:00.123456+00:00|UTC","quantity":10}]}}';

        $this->expectException(\UnexpectedValueException::class);
        (new WaybillFuelType())->convertToPHPValue($json, new PostgreSQLPlatform());
    }

    /** @return iterable<string, array{int}> */
    public static function mismatchedTotals(): iterable
    {
        yield 'different total' => [11];
        yield 'zero total' => [0];
    }

    private function roundTrip(Fuel $fuel): Fuel
    {
        $type = new WaybillFuelType();
        $platform = new PostgreSQLPlatform();
        $restored = $type->convertToPHPValue($type->convertToDatabaseValue($fuel, $platform), $platform);
        self::assertNotNull($restored);

        return $restored;
    }
}
