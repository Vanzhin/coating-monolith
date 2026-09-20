<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Templating;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TextValue;
use PHPUnit\Framework\TestCase;

final class RenderDataTest extends TestCase
{
    public function test_returns_repeat_value_by_name(): void
    {
        $repeat = new RepeatValue([
            ['organization' => 'ЗМК Наста', 'name' => 'Иванов И.И.'],
            ['organization' => 'Литум', 'name' => 'Петров П.П.'],
        ]);
        $data = new RenderData(['act_number' => new TextValue('01'), 'commission' => $repeat]);

        self::assertTrue($data->has('commission'));
        self::assertSame($repeat, $data->get('commission'));
        self::assertCount(2, $data->get('commission')->rows);
    }

    public function test_repeat_group_names_lists_only_repeat_values(): void
    {
        $data = new RenderData([
            'act_number' => new TextValue('01'),
            'commission' => new RepeatValue([['name' => 'Иванов']]),
            'process' => new RepeatValue([]),
        ]);

        $groups = $data->repeatGroupNames();
        sort($groups);
        self::assertSame(['commission', 'process'], $groups);
    }
}
