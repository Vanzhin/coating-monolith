<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\WriteOffAct;

use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Shared\Domain\ValueObject\Commission;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WriteOffActTest extends TestCase
{
    private function act(): WriteOffAct
    {
        return new WriteOffAct(Uuid::v7(), 'req-1', 'org-1', new \DateTimeImmutable('2026-08-01'));
    }

    public function test_add_lines_grouped_by_profile_and_deduped(): void
    {
        $act = $this->act();
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]); // дубль
        $act->addLinesForProfile('p2', [['recordId' => 'r2', 'labelSnapshot' => 'Каска', 'quantity' => 1.0]]);

        self::assertCount(2, $act->lines(), 'дубль по recordId не добавлен');
        self::assertSame(['p1', 'p2'], $act->profileIds());
        self::assertCount(1, $act->linesByProfile()['p1']);
        self::assertCount(1, $act->linesByProfile()['p2']);
    }

    public function test_add_lines_rejects_non_positive_quantity(): void
    {
        $act = $this->act();
        $this->expectException(AppException::class);
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 0.0]]);
    }

    public function test_add_lines_is_atomic_bad_line_in_batch_adds_nothing(): void
    {
        $act = $this->act();

        try {
            $act->addLinesForProfile('p1', [
                ['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0],
                ['recordId' => 'r2', 'labelSnapshot' => 'Каска', 'quantity' => 0.0], // битая — весь батч не применяется
            ]);
            self::fail('Ожидалось AppException из-за quantity<=0 во второй строке батча.');
        } catch (AppException) {
            // ожидаемо
        }

        self::assertCount(0, $act->lines(), 'батч с битой строкой не должен добавить ни одной строки');
    }

    public function test_add_lines_only_allowed_in_draft(): void
    {
        $act = $this->act();
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);
        $act->replacePortions([['recordId' => 'r1', 'quantity' => 10.0, 'reason' => WriteOffReason::PhysicalWear]]);
        $act->sign(Commission::fromRows([['fio' => 'Петров']]), '39', new \DateTimeImmutable('2026-08-01'), 'scan-1', new \DateTimeImmutable('2026-08-01'));

        $this->expectException(AppException::class);
        $act->addLinesForProfile('p2', [['recordId' => 'r2', 'labelSnapshot' => 'Каска', 'quantity' => 1.0]]);
    }

    public function test_sign_requires_reason_on_every_line(): void
    {
        $act = $this->act();
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);

        $this->expectException(AppException::class);
        $act->sign(Commission::fromRows([['fio' => 'Петров']]), '39', new \DateTimeImmutable('2026-08-01'), 'scan-1', new \DateTimeImmutable('2026-08-01'));
    }

    public function test_sign_requires_non_empty_commission(): void
    {
        $act = $this->act();
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);
        $act->replacePortions([['recordId' => 'r1', 'quantity' => 10.0, 'reason' => WriteOffReason::PhysicalWear]]);

        $this->expectException(AppException::class);
        $act->sign(new Commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan-1', new \DateTimeImmutable('2026-08-01'));
    }

    public function test_sign_requires_non_empty_scan(): void
    {
        $act = $this->act();
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);
        $act->replacePortions([['recordId' => 'r1', 'quantity' => 10.0, 'reason' => WriteOffReason::PhysicalWear]]);

        $this->expectException(AppException::class);
        $act->sign(Commission::fromRows([['fio' => 'Петров']]), '39', new \DateTimeImmutable('2026-08-01'), '', new \DateTimeImmutable('2026-08-01'));
    }

    public function test_replace_portions_preserves_profile_and_label_snapshot(): void
    {
        $act = $this->act();
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);
        $act->replacePortions([['recordId' => 'r1', 'quantity' => 3.0, 'reason' => WriteOffReason::TermExpired]]);

        $line = $act->lines()[0];
        self::assertSame('p1', $line->profileId());
        self::assertSame('Перчатки', $line->labelSnapshot());
        self::assertSame(3.0, $line->quantity());
        self::assertSame(WriteOffReason::TermExpired, $line->reason());
    }

    public function test_replace_portions_is_set_semantics_drops_missing_lines(): void
    {
        $act = $this->act();
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);
        $act->addLinesForProfile('p1', [['recordId' => 'r2', 'labelSnapshot' => 'Каска', 'quantity' => 1.0]]);

        $act->replacePortions([['recordId' => 'r1', 'quantity' => 5.0, 'reason' => WriteOffReason::PhysicalWear]]);

        self::assertCount(1, $act->lines());
        self::assertSame('r1', $act->lines()[0]->recordId());
    }

    public function test_replace_portions_rejects_unknown_record_id(): void
    {
        $act = $this->act();
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);

        $this->expectException(AppException::class);
        $act->replacePortions([['recordId' => 'unknown', 'quantity' => 5.0, 'reason' => WriteOffReason::PhysicalWear]]);
    }

    public function test_remove_lines_by_record_ids(): void
    {
        $act = $this->act();
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);
        $act->addLinesForProfile('p2', [['recordId' => 'r2', 'labelSnapshot' => 'Каска', 'quantity' => 1.0]]);

        $act->removeLinesByRecordIds(['r1']);

        self::assertCount(1, $act->lines());
        self::assertSame('r2', $act->lines()[0]->recordId());
    }

    public function test_remove_lines_by_record_ids_blocked_after_sign(): void
    {
        $act = $this->act();
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);
        $act->replacePortions([['recordId' => 'r1', 'quantity' => 10.0, 'reason' => WriteOffReason::PhysicalWear]]);
        $act->sign(Commission::fromRows([['fio' => 'Петров']]), '39', new \DateTimeImmutable('2026-08-01'), 'scan-1', new \DateTimeImmutable('2026-08-01'));

        $this->expectException(AppException::class); // подписанный акт заморожен — строки не снимаем даже каскадом
        $act->removeLinesByRecordIds(['r1']);
    }

    public function test_replace_portions_blocked_after_sign(): void
    {
        $act = $this->act();
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);
        $act->replacePortions([['recordId' => 'r1', 'quantity' => 10.0, 'reason' => WriteOffReason::PhysicalWear]]);
        $act->sign(Commission::fromRows([['fio' => 'Петров']]), '39', new \DateTimeImmutable('2026-08-01'), 'scan-1', new \DateTimeImmutable('2026-08-01'));

        $this->expectException(AppException::class);
        $act->replacePortions([['recordId' => 'r1', 'quantity' => 5.0, 'reason' => WriteOffReason::TermExpired]]);
    }

    public function test_save_details_blocked_after_sign(): void
    {
        $act = $this->act();
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);
        $act->replacePortions([['recordId' => 'r1', 'quantity' => 10.0, 'reason' => WriteOffReason::PhysicalWear]]);
        $act->sign(Commission::fromRows([['fio' => 'Петров']]), '39', new \DateTimeImmutable('2026-08-01'), 'scan-1', new \DateTimeImmutable('2026-08-01'));

        $this->expectException(AppException::class);
        $act->saveDetails(
            '40',
            new \DateTimeImmutable('2026-08-02'),
            Commission::fromRows([['fio' => 'Иванов']]),
            '',
            null,
            '',
            '',
            new \DateTimeImmutable('2026-08-02'),
        );
    }

    public function test_signed_act_is_immutable(): void
    {
        $act = $this->act();
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);
        $act->replacePortions([['recordId' => 'r1', 'quantity' => 10.0, 'reason' => WriteOffReason::PhysicalWear]]);
        $act->sign(Commission::fromRows([['fio' => 'Петров']]), '39', new \DateTimeImmutable('2026-08-01'), 'scan-1', new \DateTimeImmutable('2026-08-01'));

        self::assertTrue($act->isSigned());

        $this->expectException(AppException::class);
        $act->addLinesForProfile('p2', [['recordId' => 'r2', 'labelSnapshot' => 'Каска', 'quantity' => 1.0]]); // Signed → assertMutable throws
    }
}
