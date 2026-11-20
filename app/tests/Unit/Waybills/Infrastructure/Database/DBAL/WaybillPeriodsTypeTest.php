<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Infrastructure\Database\DBAL;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use App\Waybills\Infrastructure\Database\DBAL\WaybillPeriodsType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

final class WaybillPeriodsTypeTest extends TestCase
{
    public function test_round_trip_preserves_order_microseconds_and_timezones(): void
    {
        $type = new WaybillPeriodsType();
        $platform = new PostgreSQLPlatform();
        $periods = [
            WaybillPeriod::fromDates(
                new \DateTimeImmutable('2026-01-01 10:00:00.123456', new \DateTimeZone('Europe/Berlin')),
                new \DateTimeImmutable('2026-01-01 16:00:00.654321+05:45'),
            ),
            WaybillPeriod::fromDates(
                new \DateTimeImmutable('2026-07-01 10:00:00.111111', new \DateTimeZone('Europe/Berlin')),
                new \DateTimeImmutable('2026-07-02 10:00:00.222222', new \DateTimeZone('Europe/Berlin')),
            ),
        ];

        $restored = $type->convertToPHPValue($type->convertToDatabaseValue($periods, $platform), $platform);

        self::assertNotNull($restored);
        self::assertCount(2, $restored);
        foreach ($periods as $index => $period) {
            self::assertSame($period->from()->format('U.uP'), $restored[$index]->from()->format('U.uP'));
            self::assertSame($period->from()->getTimezone()->getName(), $restored[$index]->from()->getTimezone()->getName());
            self::assertSame($period->to()->format('U.uP'), $restored[$index]->to()->format('U.uP'));
            self::assertSame($period->to()->getTimezone()->getName(), $restored[$index]->to()->getTimezone()->getName());
        }
    }

    public function test_empty_and_null_are_distinct(): void
    {
        $type = new WaybillPeriodsType();
        $platform = new PostgreSQLPlatform();
        self::assertSame('[]', $type->convertToDatabaseValue([], $platform));
        self::assertSame([], $type->convertToPHPValue('[]', $platform));
        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
    }

    public function test_invalid_shape_is_rejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new WaybillPeriodsType())->convertToPHPValue('[{"from":"2026-01-01"}]', new PostgreSQLPlatform());
    }

    public function test_invalid_period_is_rejected_by_domain(): void
    {
        $this->expectException(AppException::class);
        (new WaybillPeriodsType())->convertToPHPValue('[{"from":"2026-01-02T00:00:00.000000+00:00|UTC","to":"2026-01-01T00:00:00.000000+00:00|UTC"}]', new PostgreSQLPlatform());
    }
}
