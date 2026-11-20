<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Database\DBAL;

use App\Shared\Infrastructure\Database\DBAL\BigDecimalType;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

final class BigDecimalTypeTest extends TestCase
{
    public function test_decimal_round_trip_does_not_lose_precision(): void
    {
        $type = new BigDecimalType();
        $platform = new PostgreSQLPlatform();
        $number = BigDecimal::of('30.00000000000000000001');

        $stored = $type->convertToDatabaseValue($number, $platform);

        self::assertSame('30.00000000000000000001', $stored);
        self::assertSame((string) $number, (string) $type->convertToPHPValue($stored, $platform));
        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
    }

    public function test_float_from_the_database_is_rejected_instead_of_silently_losing_precision(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new BigDecimalType())->convertToPHPValue(0.1, new PostgreSQLPlatform());
    }
}
