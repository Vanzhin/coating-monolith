<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\ValueObject\Commission;
use App\Shared\Domain\ValueObject\CommissionMember;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class CommissionTest extends TestCase
{
    public function test_member_requires_fio(): void
    {
        $this->expectException(AppException::class);
        new CommissionMember('   ');
    }

    public function test_from_rows_drops_rows_without_fio_and_maps_name_to_fio(): void
    {
        $commission = Commission::fromRows([
            ['organization' => 'ООО «ЛИТУМ»', 'position' => 'Инспектор', 'name' => 'Ванжин Н. С.', 'date' => '2026-09-25'],
            ['organization' => 'ООО «ЕВРАЗ»', 'position' => 'Технолог', 'name' => ''],   // без ФИО — отбрасываем
            ['position' => 'Кладовщик'],                                                  // без ФИО — отбрасываем
            ['name' => 'Корзун П. Е.'],
        ]);

        self::assertCount(2, $commission->members);
        self::assertSame('Ванжин Н. С.', $commission->members[0]->fio);
        self::assertSame('ООО «ЛИТУМ»', $commission->members[0]->organization);
        self::assertSame('Инспектор', $commission->members[0]->position);
        self::assertSame('2026-09-25', $commission->members[0]->date);
        self::assertSame('Корзун П. Е.', $commission->members[1]->fio);
    }

    public function test_empty_when_no_valid_rows(): void
    {
        self::assertTrue(Commission::fromRows([['name' => '']])->isEmpty());
    }

    public function test_json_round_trip(): void
    {
        $commission = Commission::fromRows([
            ['organization' => 'Орг', 'position' => 'Долж', 'name' => 'Иванов И. И.', 'date' => '2026-01-01'],
        ]);
        $restored = Commission::fromArray($commission->jsonSerialize());

        self::assertEquals($commission, $restored);
    }

    public function test_from_array_tolerates_legacy_representative_shape(): void
    {
        $commission = Commission::fromArray([
            'representative' => ['position' => 'рук.', 'fio' => 'Старый П.'],
            'members' => [['position' => 'спец.', 'fio' => 'Член К.']],
        ]);

        self::assertCount(1, $commission->members);
        self::assertSame('Член К.', $commission->members[0]->fio);
    }
}
