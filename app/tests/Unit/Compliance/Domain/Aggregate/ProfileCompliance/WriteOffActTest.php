<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffAct;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Compliance\Domain\ValueObject\WriteOffCommission;
use App\Compliance\Domain\ValueObject\WriteOffCommissionMember;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WriteOffActTest extends TestCase
{
    private function act(): WriteOffAct
    {
        return new WriteOffAct(Uuid::v4(), new ProfileCompliance(Uuid::v4(), 'p'), 'req-1', new \DateTimeImmutable('2026-08-01'));
    }

    private function commission(): WriteOffCommission
    {
        return new WriteOffCommission(
            new WriteOffCommissionMember('рук. ОТиПБ', 'Алиханова Н.И.'),
            new WriteOffCommissionMember('спец. ТМЦ', 'Корзун П.Е.'),
        );
    }

    public function test_add_portion_dedups_by_record_and_sums(): void
    {
        $act = $this->act();
        $rec = (string) Uuid::v4();
        $act->addPortion($rec, 1.0, Uuid::v4(), new \DateTimeImmutable('2026-08-01'));
        $act->addPortion($rec, 1.0, Uuid::v4(), new \DateTimeImmutable('2026-08-01')); // тот же факт → +кол-во

        self::assertCount(1, $act->items());
        self::assertSame(2.0, $act->items()[0]->quantity());
    }

    public function test_sign_requires_reason_on_every_portion(): void
    {
        $act = $this->act();
        $act->addPortion((string) Uuid::v4(), 1.0, Uuid::v4(), new \DateTimeImmutable('2026-08-01'));

        $this->expectException(AppException::class);
        $act->sign($this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan-1', new \DateTimeImmutable('2026-08-01'));
    }

    public function test_apply_reasons_then_sign_ok(): void
    {
        $act = $this->act();
        $act->addPortion((string) Uuid::v4(), 1.0, $pid = Uuid::v4(), new \DateTimeImmutable('2026-08-01'));
        $act->applyReasons([(string) $pid => WriteOffReason::PhysicalWear], new \DateTimeImmutable('2026-08-01'));

        $act->sign($this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan-1', new \DateTimeImmutable('2026-08-01'));
        self::assertTrue($act->isSigned());
    }
}
