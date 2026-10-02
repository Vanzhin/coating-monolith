<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffAct;
use App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffItem;
use App\Compliance\Domain\Type\WriteOffReason;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WriteOffItemTest extends TestCase
{
    public function test_portion_holds_record_quantity_and_reason(): void
    {
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $act = new WriteOffAct(Uuid::v4(), $pc, 'req-1', new \DateTimeImmutable('2026-08-01'));
        $recordId = (string) Uuid::v4();

        $item = new WriteOffItem(Uuid::v4(), $act, $recordId, 1.0);
        self::assertSame($recordId, $item->recordId());
        self::assertSame(1.0, $item->quantity());
        self::assertNull($item->reason());

        $item->addQuantity(1.0);
        self::assertSame(2.0, $item->quantity());

        $item->setReason(WriteOffReason::PhysicalWear);
        self::assertSame(WriteOffReason::PhysicalWear, $item->reason());
    }
}
