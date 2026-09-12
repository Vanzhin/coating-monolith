<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Domain\Aggregate\ValueObject\PartsRatio;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class PartsRatioTest extends TestCase
{
    public function test_rejects_fewer_than_two_components(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/минимум два/');
        new PartsRatio(3.0);
    }

    public function test_rejects_zero_part(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/положительной/');
        new PartsRatio(3.0, 0.0);
    }

    public function test_rejects_negative_part(): void
    {
        $this->expectException(AppException::class);
        new PartsRatio(3.0, -1.0);
    }

    public function test_exposes_parts_and_count(): void
    {
        $ratio = new PartsRatio(3.0, 1.0);

        self::assertSame([3.0, 1.0], $ratio->getParts());
        self::assertSame(2, $ratio->count());
    }

    public function test_from_base_scales_additions(): void
    {
        $dose = (new PartsRatio(3.0, 1.0))->fromBase(30.0);

        self::assertSame(30.0, $dose->getBase());
        self::assertSame([10.0], $dose->getAdditions());
        self::assertEqualsWithDelta(40.0, $dose->getTotal(), 1e-9);
    }

    public function test_from_component_computes_base_from_hardener_index(): void
    {
        // Отмерили 10 второго компонента (индекс 1) у соотношения 3:1 -> основы нужно 30.
        $dose = (new PartsRatio(3.0, 1.0))->fromComponent(1, 10.0);

        self::assertSame(30.0, $dose->getBase());
        self::assertSame([10.0], $dose->getAdditions());
    }

    public function test_from_component_scales_all_three_components(): void
    {
        // 4:1:0.5, отмерили 8 основы -> scale 2 -> [8, 2, 1].
        $dose = (new PartsRatio(4.0, 1.0, 0.5))->fromComponent(0, 8.0);

        self::assertSame(8.0, $dose->getBase());
        self::assertSame([2.0, 1.0], $dose->getAdditions());
        self::assertEqualsWithDelta(11.0, $dose->getTotal(), 1e-9);
    }

    public function test_from_component_rejects_index_out_of_range(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/номером/');
        (new PartsRatio(3.0, 1.0))->fromComponent(2, 5.0);
    }

    public function test_from_component_rejects_non_positive_amount(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/положительным/');
        (new PartsRatio(3.0, 1.0))->fromComponent(0, 0.0);
    }

    public function test_from_hardener_on_two_component_ratio(): void
    {
        $dose = (new PartsRatio(3.0, 1.0))->fromHardener(10.0);

        self::assertSame(30.0, $dose->getBase());
        self::assertSame([10.0], $dose->getAdditions());
    }

    public function test_from_hardener_rejects_more_than_two_components(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/уточните/');
        (new PartsRatio(4.0, 1.0, 0.5))->fromHardener(5.0);
    }

    public function test_from_total_splits_by_proportion(): void
    {
        $dose = (new PartsRatio(3.0, 1.0))->fromTotal(8.0);

        self::assertEqualsWithDelta(6.0, $dose->getBase(), 1e-9);
        self::assertEqualsWithDelta([2.0], $dose->getAdditions(), 1e-9);
        self::assertEqualsWithDelta(8.0, $dose->getTotal(), 1e-9);
    }

    public function test_from_total_rejects_non_positive(): void
    {
        $this->expectException(AppException::class);
        (new PartsRatio(3.0, 1.0))->fromTotal(0.0);
    }

    public function test_mass_ratio_with_non_integer_result(): void
    {
        // Массовое 100:23, отмерили 5 основы -> отвердителя 1.15.
        $dose = (new PartsRatio(100.0, 23.0))->fromBase(5.0);

        self::assertSame(5.0, $dose->getBase());
        self::assertEqualsWithDelta([1.15], $dose->getAdditions(), 1e-9);
    }
}
