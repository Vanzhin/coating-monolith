<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Database\DBAL;

use App\Shared\Infrastructure\Database\DBAL\PreciseDateTimeTzType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

final class PreciseDateTimeTzTypeTest extends TestCase
{
    public function test_postgres_declaration_and_round_trip_preserve_microseconds(): void
    {
        $type = new PreciseDateTimeTzType();
        $platform = new PostgreSQLPlatform();
        $date = new \DateTimeImmutable('2026-01-02 03:04:05.123456+03:00');

        self::assertSame('TIMESTAMP(6) WITH TIME ZONE', $type->getSQLDeclaration([], $platform));
        self::assertSame('2026-01-02 03:04:05.123456+03:00', $type->convertToDatabaseValue($date, $platform));
        self::assertSame($date->format('U.u'), $type->convertToPHPValue('2026-01-02 00:04:05.123456+00', $platform)?->format('U.u'));
        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
    }
}
