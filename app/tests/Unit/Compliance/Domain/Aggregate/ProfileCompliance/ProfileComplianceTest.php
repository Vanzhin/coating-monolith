<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Aggregate\ProfileCompliance\DocumentStatus;
use App\Compliance\Domain\Aggregate\ProfileCompliance\IssuanceLine;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Service\ComplianceStatusResolver;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceStatus;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use App\Compliance\Domain\ValueObject\WriteOffCommission;
use App\Compliance\Domain\ValueObject\WriteOffCommissionMember;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ProfileComplianceTest extends TestCase
{
    private ObligationDueCalculator $calc;
    private ComplianceStatusResolver $resolver;
    private \DateTimeImmutable $now;
    private string $reqId = 'req-1';

    protected function setUp(): void
    {
        $this->calc = new ObligationDueCalculator();
        $this->resolver = new ComplianceStatusResolver();
        $this->now = new \DateTimeImmutable('2026-06-01');
    }

    private function pcWithGloves(float $norm = 10.0): ProfileCompliance
    {
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $pc->putObligation(new TrackedObligation(
            Uuid::v4(), $pc, $this->reqId, 'СИЗ основные', 'Перчатки',
            ComplianceType::Material, new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year),
            new Quantity($norm, Unit::Pair), 'dept-1',
        ));

        return $pc;
    }

    private function glovesKey(): string
    {
        return TrackedObligation::keyOf($this->reqId, 'Перчатки');
    }

    public function test_unsigned_is_red_even_when_fulfilled(): void
    {
        $pc = $this->pcWithGloves();
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc);

        // Документ не подписан (active=false по умолчанию) → не исполнено, несмотря на выдачу.
        self::assertSame(ComplianceStatus::Red, $pc->worstStatus($this->resolver, null, $this->now));
    }

    public function test_fulfillment_recomputes_dates(): void
    {
        $pc = $this->pcWithGloves();
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc);

        $ob = $pc->getObligations()[0];
        self::assertSame('2026-01-10', $ob->lastFulfilledAt()?->format('Y-m-d'));
        self::assertSame('2027-01-10', $ob->nextDueAt()?->format('Y-m-d'));
    }

    public function test_signed_and_fulfilled_far_is_green(): void
    {
        $pc = $this->pcWithGloves();
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc);
        $pc->setActiveForRequirement($this->reqId, true);

        self::assertSame(ComplianceStatus::Green, $pc->worstStatus($this->resolver, null, $this->now));
    }

    public function test_removing_last_record_rolls_back_dates_and_status(): void
    {
        $pc = $this->pcWithGloves();
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc);
        $pc->setActiveForRequirement($this->reqId, true);

        $recId = $pc->getRecords()[0]->getId();
        $pc->removeRecord($recId, $this->calc);

        self::assertNull($pc->getObligations()[0]->lastFulfilledAt());
        self::assertNull($pc->getObligations()[0]->nextDueAt());
        self::assertSame(ComplianceStatus::Red, $pc->worstStatus($this->resolver, null, $this->now)); // active, но не выдано
    }

    public function test_type_filter_in_worst_status(): void
    {
        $pc = $this->pcWithGloves();
        $pc->setActiveForRequirement($this->reqId, true);
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc);

        self::assertSame(ComplianceStatus::Green, $pc->worstStatus($this->resolver, ComplianceType::Material, $this->now));
        self::assertSame(ComplianceStatus::Green, $pc->worstStatus($this->resolver, ComplianceType::NonMaterial, $this->now)); // нет нематериальных — худший остаётся Green
    }

    public function test_exclude_removes_obligation(): void
    {
        $pc = $this->pcWithGloves();
        $pc->excludeObligation($this->glovesKey());

        self::assertCount(0, $pc->getObligations());
        self::assertContains($this->glovesKey(), $pc->getExcludedKeys()->getList());
    }

    public function test_form_draft_creates_open_draft(): void
    {
        $pc = $this->pcWithGloves();
        $pc->formDraft(Uuid::v4(), $this->reqId, $this->now);

        $draft = $pc->openDraftFor($this->reqId);
        self::assertNotNull($draft);
        self::assertTrue($draft->isDraft());
    }

    public function test_second_open_draft_throws(): void
    {
        $pc = $this->pcWithGloves();
        $pc->formDraft(Uuid::v4(), $this->reqId, $this->now);

        $this->expectException(AppException::class);
        $pc->formDraft(Uuid::v4(), $this->reqId, $this->now);
    }

    public function test_sign_draft_requires_scan(): void
    {
        $pc = $this->pcWithGloves();
        $id = Uuid::v4();
        $pc->formDraft($id, $this->reqId, $this->now);

        $this->expectException(AppException::class);
        $pc->signDraft((string) $id, null, [$this->glovesLine(10.0)], $this->calc, $this->now);
    }

    public function test_sign_draft_below_norm_errors(): void
    {
        $pc = $this->pcWithGloves(); // норма 10 пар
        $id = Uuid::v4();
        $pc->formDraft($id, $this->reqId, $this->now);

        $this->expectException(AppException::class);
        $pc->signDraft((string) $id, 'scan-1', [$this->glovesLine(5.0)], $this->calc, $this->now);
    }

    public function test_sign_draft_records_signs_and_activates(): void
    {
        $pc = $this->pcWithGloves();
        $id = Uuid::v4();
        $pc->formDraft($id, $this->reqId, $this->now);
        $pc->signDraft((string) $id, 'scan-1', [$this->glovesLine(10.0)], $this->calc, new \DateTimeImmutable('2026-06-01'));

        self::assertSame(DocumentStatus::Signed, $pc->signedDocumentsFor($this->reqId)[0]->status());
        self::assertNull($pc->openDraftFor($this->reqId)); // черновик стал подписанным актом
        self::assertTrue($pc->getObligations()[0]->isActive());
        self::assertCount(1, $pc->getRecords());
        self::assertSame('2027-06-01', $pc->getObligations()[0]->nextDueAt()?->format('Y-m-d'));
    }

    public function test_renewal_creates_second_signed_act(): void
    {
        $pc = $this->pcWithGloves();
        $first = Uuid::v4();
        $pc->formDraft($first, $this->reqId, $this->now);
        $pc->signDraft((string) $first, 'scan-1', [$this->glovesLine(10.0)], $this->calc, new \DateTimeImmutable('2026-06-01'));

        // Продление: первый акт подписан → можно завести второй черновик по тому же требованию.
        $second = Uuid::v4();
        $pc->formDraft($second, $this->reqId, $this->now);
        $pc->signDraft((string) $second, 'scan-2', [$this->glovesLine(10.0, '2027-06-01')], $this->calc, new \DateTimeImmutable('2027-06-01'));

        self::assertCount(2, $pc->signedDocumentsFor($this->reqId));
        self::assertSame('2027-06-01', $pc->getObligations()[0]->lastFulfilledAt()?->format('Y-m-d'));
    }

    public function test_delete_draft_removes_it(): void
    {
        $pc = $this->pcWithGloves();
        $id = Uuid::v4();
        $pc->formDraft($id, $this->reqId, $this->now);
        $pc->deleteDraft((string) $id);

        self::assertNull($pc->openDraftFor($this->reqId));
        self::assertCount(0, $pc->getDocuments());
    }

    public function test_delete_signed_act_throws(): void
    {
        $pc = $this->pcWithGloves();
        $id = Uuid::v4();
        $pc->formDraft($id, $this->reqId, $this->now);
        $pc->signDraft((string) $id, 'scan-1', [$this->glovesLine(10.0)], $this->calc, $this->now);

        $this->expectException(AppException::class);
        $pc->deleteDraft((string) $id);
    }

    private function signedGlovesCard(ProfileCompliance $pc, \DateTimeImmutable $at, float $amount = 10.0): void
    {
        $docId = Uuid::v4();
        $pc->formDraft($docId, $this->reqId, $at);
        $pc->signDraft((string) $docId, 'scan-1', [$this->glovesLine($amount, $at->format('Y-m-d'))], $this->calc, $at);
    }

    public function test_write_off_portion_without_effect(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->signedGlovesCard($pc, new \DateTimeImmutable('2026-06-01'), 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $writeOffId = Uuid::v4();

        $pc->writeOff($writeOffId, $this->reqId, [['recordId' => $recordId, 'quantity' => 1.0]], $this->now);

        // эффекта нет — на руках всё ещё 2, нового черновика нет
        self::assertSame(2.0, $pc->heldOf($this->glovesKey()));
        self::assertNull($pc->openDraftFor($this->reqId));
        self::assertCount(1, $pc->getWriteOffActs());
        self::assertCount(1, $pc->itemsOfWriteOffAct((string) $writeOffId));
        self::assertSame(1.0, $pc->itemsOfWriteOffAct((string) $writeOffId)[0]->quantity());
        // доступно к списанию уменьшилось на лежащее в черновике
        self::assertSame(1.0, $pc->availableToWriteOff($recordId));
    }

    public function test_write_off_more_than_available_throws(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->signedGlovesCard($pc, $this->now, 2.0);
        $recordId = $pc->getRecords()[0]->getId();

        $this->expectException(AppException::class);
        $pc->writeOff(Uuid::v4(), $this->reqId, [['recordId' => $recordId, 'quantity' => 3.0]], $this->now);
    }

    public function test_repeated_write_off_same_fact_increases_portion(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->signedGlovesCard($pc, $this->now, 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $writeOffId = Uuid::v4();

        $pc->writeOff($writeOffId, $this->reqId, [['recordId' => $recordId, 'quantity' => 1.0]], $this->now);
        $pc->writeOff(Uuid::v4(), $this->reqId, [['recordId' => $recordId, 'quantity' => 1.0]], $this->now); // тот же факт, тот же черновик

        self::assertCount(1, $pc->getWriteOffActs());
        self::assertCount(1, $pc->itemsOfWriteOffAct((string) $writeOffId));
        self::assertSame(2.0, $pc->itemsOfWriteOffAct((string) $writeOffId)[0]->quantity());
    }

    public function test_cancel_portion_restores_and_drops_empty_act(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->signedGlovesCard($pc, $this->now, 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $writeOffId = Uuid::v4();
        $pc->writeOff($writeOffId, $this->reqId, [['recordId' => $recordId, 'quantity' => 1.0]], $this->now);
        $portionId = $pc->itemsOfWriteOffAct((string) $writeOffId)[0]->getId();

        $pc->cancelWriteOffItem((string) $writeOffId, $portionId, $this->now);

        self::assertCount(0, $pc->getWriteOffActs());
        self::assertSame(2.0, $pc->availableToWriteOff($recordId));
    }

    public function test_write_off_requires_active_card(): void
    {
        $pc = $this->pcWithGloves();
        $pc->formDraft(Uuid::v4(), $this->reqId, $this->now); // только черновик выдачи

        $this->expectException(AppException::class);
        $pc->writeOff(Uuid::v4(), $this->reqId, [['recordId' => (string) Uuid::v4(), 'quantity' => 1.0]], $this->now);
    }

    public function test_sign_act_applies_partial_write_off(): void
    {
        $pc = $this->pcWithGloves(2.0); // норма 2
        $this->signedGlovesCard($pc, new \DateTimeImmutable('2026-06-01'), 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $writeOffId = Uuid::v4();
        $pc->writeOff($writeOffId, $this->reqId, [['recordId' => $recordId, 'quantity' => 1.0]], $this->now);
        $portionId = $pc->itemsOfWriteOffAct((string) $writeOffId)[0]->getId();
        $pc->applyWriteOffReasons((string) $writeOffId, [$portionId => WriteOffReason::PhysicalWear], $this->now);

        $pc->signWriteOffAct((string) $writeOffId, $this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan-wo', $this->now, $this->calc);

        self::assertTrue($pc->getWriteOffActs()[0]->isSigned());
        self::assertSame(1.0, $pc->heldOf($this->glovesKey())); // на руках 1 из 2
        self::assertNotNull($pc->getObligations()[0]->lastFulfilledAt());

        // списываем второй
        $w2 = Uuid::v4();
        $pc->writeOff($w2, $this->reqId, [['recordId' => $recordId, 'quantity' => 1.0]], $this->now);
        $p2 = $pc->itemsOfWriteOffAct((string) $w2)[0]->getId();
        $pc->applyWriteOffReasons((string) $w2, [$p2 => WriteOffReason::PhysicalWear], $this->now);
        $pc->signWriteOffAct((string) $w2, $this->commission(), '40', new \DateTimeImmutable('2026-09-01'), 'scan-wo2', $this->now, $this->calc);

        self::assertSame(0.0, $pc->heldOf($this->glovesKey())); // всё списано
        self::assertNull($pc->getObligations()[0]->lastFulfilledAt()); // позиция освобождена
    }

    public function test_projection_holds_quantity(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->signedGlovesCard($pc, new \DateTimeImmutable('2026-06-01'), 2.0);

        self::assertSame(2.0, $pc->getObligations()[0]->heldQuantity());
    }

    private function commission(): WriteOffCommission
    {
        return new WriteOffCommission(
            new WriteOffCommissionMember('руководитель отдела ОТ и ПБ', 'Алиханова Н.И.'),
            new WriteOffCommissionMember('специалист по учету ТМЦ', 'Корзун П.Е.'),
        );
    }

    private function glovesLine(float $amount, string $issueDate = '2026-06-01'): IssuanceLine
    {
        return new IssuanceLine(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable($issueDate), new Quantity($amount, Unit::Pair));
    }
}
