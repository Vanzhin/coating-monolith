<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Route;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Settlement;
use App\Waybills\Domain\Aggregate\Route\SettlementType;
use PHPUnit\Framework\TestCase;

final class SettlementTest extends TestCase
{
    public function test_formats_typed_settlement_and_round_trip(): void
    {
        $settlement = new Settlement(' Екатеринбург ', SettlementType::City);
        $restored = Settlement::fromArray($settlement->jsonSerialize());

        self::assertSame('Екатеринбург', $restored->name);
        self::assertSame(SettlementType::City, $restored->type);
        self::assertSame('г. Екатеринбург', $restored->toString());
        self::assertTrue($settlement->equals($restored));
    }

    public function test_type_contains_full_and_abbreviated_labels(): void
    {
        $expected = [
            SettlementType::Village->value => ['Деревня', 'д.'],
            SettlementType::Selo->value => ['Село', 'с.'],
            SettlementType::Settlement->value => ['Посёлок', 'п.'],
            SettlementType::Khutor->value => ['Хутор', 'х.'],
            SettlementType::UrbanSettlement->value => ['Посёлок городского типа', 'пгт'],
            SettlementType::City->value => ['Город', 'г.'],
        ];
        foreach ($expected as $value => [$label, $abbreviation]) {
            $type = SettlementType::from($value);
            self::assertSame($label, $type->label());
            self::assertSame($abbreviation, $type->abbreviation());
        }
    }

    public function test_legacy_string_has_no_assumed_type(): void
    {
        $settlement = Settlement::fromArray('База');

        self::assertSame('База', $settlement->name);
        self::assertNull($settlement->type);
        self::assertSame('База', $settlement->toString());
    }

    public function test_rejects_blank_name(): void
    {
        $this->expectException(AppException::class);
        new Settlement(' ');
    }
}
