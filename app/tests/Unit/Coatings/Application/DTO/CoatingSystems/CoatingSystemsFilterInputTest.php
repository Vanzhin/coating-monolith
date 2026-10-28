<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Application\DTO\CoatingSystems;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use PHPUnit\Framework\TestCase;

/**
 * toArray/fromArray — линчпин хранения распознанных параметров (Inquiries держит их как
 * непрозрачный массив и ре-прогоняет поиск). Любое потерянное/перепутанное поле = тихо неверный
 * подбор, который заглушки query-шины в тестах движка не поймают. Пин round-trip, в т.ч. через JSON
 * (имитация jsonb: что реально вернёт БД/ре-дамп).
 */
final class CoatingSystemsFilterInputTest extends TestCase
{
    public function test_round_trip_preserves_all_fields(): void
    {
        $input = $this->full();

        self::assertEquals($input, CoatingSystemsFilterInput::fromArray($input->toArray()));
    }

    public function test_round_trip_through_json_like_jsonb(): void
    {
        $input = $this->full();

        $decoded = json_decode((string) json_encode($input->toArray()), true);
        self::assertIsArray($decoded);

        self::assertEquals($input, CoatingSystemsFilterInput::fromArray($decoded));
    }

    public function test_empty_array_yields_defaults(): void
    {
        $input = CoatingSystemsFilterInput::fromArray([]);

        self::assertNull($input->search);
        self::assertSame([], $input->substrates);
        self::assertFalse($input->thermalIncludingPeak);
        self::assertNull($input->hasDocuments);
        self::assertSame(1, $input->page);
        self::assertSame(20, $input->limit);
    }

    private function full(): CoatingSystemsFilterInput
    {
        return new CoatingSystemsFilterInput(
            search: 'пропарка',
            substrates: ['steel_carbon', 'concrete'],
            environment: 'immersion',
            standard: 'iso12944',
            category: 'C5',
            durability: 'high',
            tagIds: ['t1', 't2'],
            coatingIds: ['c1'],
            substanceIds: ['s1', 's2'],
            applicationMinTempFrom: 5,
            applicationMinTempTo: 40,
            minApplicationTimeFromMinutes: 120,
            minApplicationTimeToMinutes: 240,
            thermalTemperature: 90,
            thermalEnvironment: 'dry_heat',
            thermalIncludingPeak: true,
            substanceTemperature: 40,
            hasDocuments: true,
            sort: 'name',
            page: 3,
            limit: 50,
        );
    }
}
