<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Database\DBAL;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Database\DBAL\PositiveNumberType;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PositiveNumberTypeTest extends TestCase
{
    public function test_float_declaration_and_fractional_round_trip(): void
    {
        $type = new PositiveNumberType();
        $platform = new PostgreSQLPlatform();
        $number = new PositiveNumber(12.75);

        self::assertSame('DOUBLE PRECISION', $type->getSQLDeclaration([], $platform));
        self::assertSame(12.75, $type->convertToDatabaseValue($number, $platform));
        self::assertSame(12.75, $type->convertToPHPValue('12.75', $platform)->value());
        self::assertSame($number, $type->convertToPHPValue($number, $platform));
        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
        self::assertSame(1.0, $type->convertToPHPValue(1, $platform)->value());
        self::assertSame(PHP_FLOAT_MIN, $type->convertToPHPValue(PHP_FLOAT_MIN, $platform)->value());
        self::assertSame(PHP_FLOAT_MAX, $type->convertToPHPValue(PHP_FLOAT_MAX, $platform)->value());
    }

    #[DataProvider('nonPositiveValues')]
    public function test_non_positive_database_values_are_rejected(int|float|string $value): void
    {
        $this->expectException(AppException::class);
        (new PositiveNumberType())->convertToPHPValue($value, new PostgreSQLPlatform());
    }

    /** @return iterable<string, array{int|float|string}> */
    public static function nonPositiveValues(): iterable
    {
        yield 'zero' => [0];
        yield 'negative fraction' => [-0.1];
        yield 'negative numeric string' => ['-1'];
    }

    #[DataProvider('invalidDatabaseValues')]
    public function test_invalid_database_types_are_rejected(mixed $value): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new PositiveNumberType())->convertToPHPValue($value, new PostgreSQLPlatform());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDatabaseValues(): iterable
    {
        yield 'text' => ['not a number'];
        yield 'empty string' => [''];
        yield 'boolean' => [true];
        yield 'array' => [[1]];
        yield 'object' => [new \stdClass()];
    }

    public function test_invalid_php_value_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        (new PositiveNumberType())->convertToDatabaseValue(1.5, new PostgreSQLPlatform());
    }
}
