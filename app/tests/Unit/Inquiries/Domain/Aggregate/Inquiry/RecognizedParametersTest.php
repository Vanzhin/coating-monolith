<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Domain\Aggregate\Inquiry;

use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;
use PHPUnit\Framework\TestCase;

final class RecognizedParametersTest extends TestCase
{
    public function test_recognized_is_all_model_and_flat(): void
    {
        $p = RecognizedParameters::recognized(['environment' => 'special', 'substanceIds' => ['a', 'b']]);

        self::assertSame(['environment' => 'special', 'substanceIds' => ['a', 'b']], $p->toArray());
        self::assertSame(ParameterStrategy::Model, $p->originOf('environment'));
        self::assertSame([], $p->manuallyEditedKeys());
        self::assertSame([], $p->corrections());
    }

    public function test_recognized_with_cache_origin(): void
    {
        $p = RecognizedParameters::recognized(['search' => 'этанол'], ParameterStrategy::Cache);

        self::assertSame(ParameterStrategy::Cache, $p->originOf('search'));
    }

    public function test_manual_edit_marks_only_changed_and_keeps_robot(): void
    {
        $p = RecognizedParameters::recognized(['environment' => 'atmospheric', 'substanceTemp' => 90])
            ->withManualEdits(['environment' => 'atmospheric', 'substanceTemp' => 80]);

        // эффективные значения — человеческие
        self::assertSame(['environment' => 'atmospheric', 'substanceTemp' => 80], $p->toArray());
        // поправили только температуру
        self::assertSame(['substanceTemp'], $p->manuallyEditedKeys());
        self::assertSame(ParameterStrategy::Model, $p->originOf('environment'));
        self::assertSame(ParameterStrategy::Manual, $p->originOf('substanceTemp'));
        // пара робот→человек видна градуснику
        self::assertSame(['substanceTemp' => ['robot' => 90, 'human' => 80]], $p->corrections());
    }

    public function test_array_axis_compared_as_set_order_insensitive(): void
    {
        $p = RecognizedParameters::recognized(['substrates' => ['a', 'b']])
            ->withManualEdits(['substrates' => ['b', 'a']]);

        self::assertSame([], $p->manuallyEditedKeys()); // порядок не считается правкой
    }

    public function test_human_adds_axis_robot_missed(): void
    {
        $p = RecognizedParameters::recognized(['environment' => 'special'])
            ->withManualEdits(['environment' => 'special', 'standard' => 'SP_28']);

        self::assertSame(['standard'], $p->manuallyEditedKeys());
        self::assertSame(['standard' => ['robot' => null, 'human' => 'SP_28']], $p->corrections());
    }

    public function test_pagination_axes_never_count_as_manual(): void
    {
        $p = RecognizedParameters::recognized(['environment' => 'special', 'page' => 1])
            ->withManualEdits(['environment' => 'special', 'page' => 3, 'limit' => 50, 'sort' => 'date']);

        self::assertSame([], $p->manuallyEditedKeys());
        self::assertSame([], $p->corrections());
    }

    public function test_jsonserialize_carries_robot_only_for_edited(): void
    {
        $p = RecognizedParameters::recognized(['environment' => 'atmospheric', 'substanceTemp' => 90])
            ->withManualEdits(['environment' => 'atmospheric', 'substanceTemp' => 80]);

        self::assertSame([
            'environment' => ['value' => 'atmospheric', 'origin' => 'model'],
            'substanceTemp' => ['value' => 80, 'origin' => 'manual', 'robotValue' => 90],
        ], $p->jsonSerialize());
    }

    public function test_repeated_edits_keep_first_robot_value(): void
    {
        // два отдельных сабмита формы подряд: оригинал робота — от ПЕРВОЙ правки, не промежуточный
        $p = RecognizedParameters::recognized(['substanceTemp' => 90])
            ->withManualEdits(['substanceTemp' => 80])
            ->withManualEdits(['substanceTemp' => 70]);

        self::assertSame(70, $p->toArray()['substanceTemp']);
        self::assertSame(['substanceTemp' => ['robot' => 90, 'human' => 70]], $p->corrections());
    }

    public function test_revert_to_robot_value_drops_from_corrections(): void
    {
        // инженер поправил и вернул обратно к роботовому — фактического расхождения нет
        $p = RecognizedParameters::recognized(['substanceTemp' => 90])
            ->withManualEdits(['substanceTemp' => 80])
            ->withManualEdits(['substanceTemp' => 90]);

        self::assertSame([], $p->corrections());
    }

    public function test_corrections_skips_legacy_empty_manual(): void
    {
        // легаси-запись: ось помечена Manual со значением null и без robotValue — не правка по смыслу
        $p = RecognizedParameters::fromArray(['durability' => ['value' => null, 'origin' => 'manual']]);

        self::assertSame([], $p->corrections());
    }

    public function test_fromarray_tolerates_legacy_flat(): void
    {
        $p = RecognizedParameters::fromArray(['environment' => 'immersion', 'substanceIds' => ['s1']]);

        self::assertSame(['environment' => 'immersion', 'substanceIds' => ['s1']], $p->toArray());
        self::assertSame(ParameterStrategy::Model, $p->originOf('environment'));
    }
}
