<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Domain\Aggregate\Inquiry;

use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedValue;
use PHPUnit\Framework\TestCase;

final class RecognizedValueTest extends TestCase
{
    public function test_recognized_robot_equals_effective(): void
    {
        $v = RecognizedValue::recognized('special');

        self::assertSame('special', $v->value);
        self::assertSame('special', $v->robotValue);
        self::assertSame(ParameterStrategy::Model, $v->origin);
        self::assertFalse($v->wasEdited());
    }

    public function test_recognized_from_cache(): void
    {
        $v = RecognizedValue::recognized(['s1'], ParameterStrategy::Cache);

        self::assertSame(ParameterStrategy::Cache, $v->origin);
        self::assertFalse($v->wasEdited());
    }

    public function test_edited_keeps_robot_original(): void
    {
        $edited = RecognizedValue::recognized(90)->editedByHuman(80);

        self::assertSame(80, $edited->value);
        self::assertSame(90, $edited->robotValue); // оригинал робота сохранён
        self::assertSame(ParameterStrategy::Manual, $edited->origin);
        self::assertTrue($edited->wasEdited());
    }

    public function test_second_edit_still_keeps_first_robot_value(): void
    {
        $twice = RecognizedValue::recognized(90)->editedByHuman(80)->editedByHuman(70);

        self::assertSame(70, $twice->value);
        self::assertSame(90, $twice->robotValue); // не промежуточное 80, а изначальное роботово
    }

    public function test_human_sets_axis_robot_had_none(): void
    {
        $added = RecognizedValue::recognized(null)->editedByHuman('SP_28');

        self::assertSame('SP_28', $added->value);
        self::assertNull($added->robotValue);
        self::assertTrue($added->wasEdited());
    }
}
