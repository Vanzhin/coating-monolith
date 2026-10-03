<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffAct;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Shared\Domain\ValueObject\Commission;
use App\Shared\Domain\ValueObject\CommissionMember;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WriteOffActTest extends TestCase
{
    private function act(): WriteOffAct
    {
        return new WriteOffAct(Uuid::v4(), new ProfileCompliance(Uuid::v4(), 'p'), 'req-1', new \DateTimeImmutable('2026-08-01'));
    }

    private function commission(): Commission
    {
        return new Commission(
            new CommissionMember('Алиханова Н.И.', position: 'рук. ОТиПБ'),
            new CommissionMember('Корзун П.Е.', position: 'спец. ТМЦ'),
        );
    }

    public function test_replace_portions_sets_items(): void
    {
        $act = $this->act();
        $rec = (string) Uuid::v4();
        $act->replacePortions([['recordId' => $rec, 'quantity' => 1.0, 'reason' => WriteOffReason::PhysicalWear]], new \DateTimeImmutable('2026-08-01'));
        $act->replacePortions([['recordId' => $rec, 'quantity' => 2.0, 'reason' => WriteOffReason::PhysicalWear]], new \DateTimeImmutable('2026-08-01')); // set, не add

        self::assertCount(1, $act->items());
        self::assertSame(2.0, $act->items()[0]->quantity());
        self::assertSame($rec, $act->items()[0]->recordId());
    }

    public function test_sign_requires_reason_on_every_portion(): void
    {
        $act = $this->act();
        $act->replacePortions([['recordId' => (string) Uuid::v4(), 'quantity' => 1.0, 'reason' => null]], new \DateTimeImmutable('2026-08-01'));

        $this->expectException(AppException::class);
        $act->sign($this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan-1', new \DateTimeImmutable('2026-08-01'));
    }

    public function test_replace_with_reason_then_sign_ok(): void
    {
        $act = $this->act();
        $act->replacePortions([['recordId' => (string) Uuid::v4(), 'quantity' => 1.0, 'reason' => WriteOffReason::PhysicalWear]], new \DateTimeImmutable('2026-08-01'));

        $act->sign($this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan-1', new \DateTimeImmutable('2026-08-01'));
        self::assertTrue($act->isSigned());
    }
}
