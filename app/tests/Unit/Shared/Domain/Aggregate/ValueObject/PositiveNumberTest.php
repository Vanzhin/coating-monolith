<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class PositiveNumberTest extends TestCase
{
    public function test_accepts_positive_int(): void
    {
        self::assertSame(3, (new PositiveNumber(3))->value());
    }

    public function test_accepts_positive_float(): void
    {
        self::assertSame(3.5, (new PositiveNumber(3.5))->value());
    }

    public function test_rejects_zero(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/положительным/');
        new PositiveNumber(0);
    }

    public function test_rejects_negative(): void
    {
        $this->expectException(AppException::class);
        new PositiveNumber(-2.5);
    }
}
