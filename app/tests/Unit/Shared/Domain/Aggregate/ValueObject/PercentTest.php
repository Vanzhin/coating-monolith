<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Domain\Aggregate\ValueObject\Percent;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PercentTest extends TestCase
{
    #[DataProvider('validValues')]
    public function test_accepts_values_in_range(int|float $value): void
    {
        self::assertSame($value, (new Percent($value))->value());
    }

    /**
     * @return iterable<string, array{int|float}>
     */
    public static function validValues(): iterable
    {
        yield 'zero' => [0];
        yield 'fraction' => [59.09];
        yield 'hundred' => [100];
    }

    #[DataProvider('outOfRange')]
    public function test_rejects_values_out_of_range(int|float $value): void
    {
        $this->expectException(AppException::class);
        new Percent($value);
    }

    /**
     * @return iterable<string, array{int|float}>
     */
    public static function outOfRange(): iterable
    {
        yield 'negative' => [-1];
        yield 'above hundred' => [100.1];
    }
}
