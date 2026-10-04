<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Aggregate\ProfileCompliance\FulfillmentRecord;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class FulfillmentRecordTest extends TestCase
{
    public function test_held_and_depletion_accumulate(): void
    {
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $r = new FulfillmentRecord(Uuid::v4(), $pc, 'req|ботинки', new \DateTimeImmutable('2026-01-10'), new Quantity(2.0, Unit::Pair));

        self::assertSame(2.0, $r->heldAmount());
        self::assertFalse($r->isDepleted());

        $r->addReturnedQuantity(1.0);
        self::assertSame(1.0, $r->heldAmount());
        self::assertFalse($r->isDepleted());
        self::assertSame(1.0, $r->returnedQuantity());

        $r->addReturnedQuantity(1.0);
        self::assertSame(0.0, $r->heldAmount());
        self::assertTrue($r->isDepleted());
    }

    public function test_non_material_fact_has_zero_held_and_not_depleted(): void
    {
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $r = new FulfillmentRecord(Uuid::v4(), $pc, 'req|инструктаж', new \DateTimeImmutable('2026-01-10'));

        self::assertSame(0.0, $r->heldAmount());
        self::assertFalse($r->isDepleted()); // нет количества — не «истощается», живёт по дате
    }
}
