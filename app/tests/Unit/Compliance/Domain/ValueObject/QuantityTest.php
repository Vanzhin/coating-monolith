<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\ValueObject;

use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class QuantityTest extends TestCase
{
    public function test_valid(): void
    {
        $q = new Quantity(10, Unit::Pair);
        self::assertSame(10.0, $q->amount);
        self::assertSame(Unit::Pair, $q->unit);
    }

    public function test_zero_amount_throws(): void
    {
        $this->expectException(AppException::class);
        new Quantity(0, Unit::Piece);
    }

    public function test_negative_amount_throws(): void
    {
        $this->expectException(AppException::class);
        new Quantity(-1, Unit::Piece);
    }

    public function test_label_integer_and_decimal(): void
    {
        self::assertSame('10 пара', (new Quantity(10, Unit::Pair))->label());
        self::assertSame('4.5 мл', (new Quantity(4.5, Unit::Milliliter))->label());
    }

    public function test_round_trip(): void
    {
        $q = new Quantity(3, Unit::Set);
        self::assertSame($q->jsonSerialize(), Quantity::fromArray($q->jsonSerialize())->jsonSerialize());
    }

    public function test_unit_titles_present(): void
    {
        foreach (Unit::cases() as $unit) {
            self::assertNotSame('', $unit->title());
        }
    }
}
