<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Database\DBAL;

use App\Shared\Infrastructure\Database\DBAL\ExactDateTimeType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExactDateTimeTypeTest extends TestCase
{
    #[DataProvider('dates')]
    public function test_round_trip_preserves_instant_offset_timezone_and_microseconds(string $date, string $zone): void
    {
        $type = new ExactDateTimeType();
        $platform = new PostgreSQLPlatform();
        $original = new \DateTimeImmutable($date, new \DateTimeZone($zone));

        $stored = $type->convertToDatabaseValue($original, $platform);
        $restored = $type->convertToPHPValue($stored, $platform);

        self::assertNotNull($restored);
        self::assertSame($original->format('U.u'), $restored->format('U.u'));
        self::assertSame($original->format('Y-m-d H:i:s.uP'), $restored->format('Y-m-d H:i:s.uP'));
        self::assertSame($original->getTimezone()->getName(), $restored->getTimezone()->getName());
    }

    /** @return iterable<string, array{string, string}> */
    public static function dates(): iterable
    {
        yield 'winter timezone' => ['2026-01-01 12:30:45.123456', 'Europe/Berlin'];
        yield 'summer timezone' => ['2026-07-01 12:30:45.654321', 'Europe/Berlin'];
        yield 'fixed offset' => ['2026-01-01 00:00:00.000001', '+05:45'];
        yield 'UTC timezone' => ['2026-01-01 00:00:00.999999', 'UTC'];
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_invalid_or_inconsistent_stored_value(string $value): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ExactDateTimeType::parse($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'missing zone' => ['2026-01-01T12:00:00.123456+01:00'];
        yield 'invalid zone' => ['2026-01-01T12:00:00.123456+01:00|Not/AZone'];
        yield 'incorrect offset' => ['2026-07-01T12:00:00.123456+01:00|Europe/Berlin'];
        yield 'invalid date' => ['2026-02-30T12:00:00.123456+01:00|Europe/Berlin'];
        yield 'extra separator' => ['2026-01-01T12:00:00.123456+01:00|Europe/Berlin|extra'];
    }

    public function test_null_is_preserved(): void
    {
        $type = new ExactDateTimeType();
        $platform = new PostgreSQLPlatform();
        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
    }
}
