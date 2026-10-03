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
use App\Shared\Domain\ValueObject\Commission;
use App\Shared\Domain\ValueObject\CommissionMember;
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
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc, new Quantity(10.0, Unit::Pair));
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
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc, new Quantity(10.0, Unit::Pair));

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

    public function test_start_returns_same_open_draft_and_requires_active_card(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->signedGlovesCard($pc, new \DateTimeImmutable('2026-06-01'), 2.0);

        $a1 = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);
        $a2 = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);

        self::assertSame($a1, $a2); // один открытый черновик
        self::assertCount(1, $pc->getWriteOffActs());
    }

    public function test_start_without_active_card_throws(): void
    {
        $pc = $this->pcWithGloves();
        $pc->formDraft(Uuid::v4(), $this->reqId, $this->now); // только черновик выдачи

        $this->expectException(AppException::class);
        $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);
    }

    public function test_save_portions_without_effect(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->signedGlovesCard($pc, new \DateTimeImmutable('2026-06-01'), 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $actId = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);

        $pc->saveWriteOffAct($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => WriteOffReason::PhysicalWear]], $this->now);

        // эффекта нет — на руках всё ещё 2, нового черновика выдачи нет
        self::assertSame(2.0, $pc->heldOf($this->glovesKey()));
        self::assertNull($pc->openDraftFor($this->reqId));
        self::assertCount(1, $pc->itemsOfWriteOffAct($actId));
        self::assertSame(1.0, $pc->itemsOfWriteOffAct($actId)[0]->quantity());
    }

    public function test_save_more_than_held_throws(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->signedGlovesCard($pc, $this->now, 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $actId = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);

        $this->expectException(AppException::class);
        $pc->saveWriteOffAct($actId, [['recordId' => $recordId, 'quantity' => 3.0, 'reason' => WriteOffReason::PhysicalWear]], $this->now);
    }

    public function test_save_replaces_portions(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->signedGlovesCard($pc, $this->now, 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $actId = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);

        $pc->saveWriteOffAct($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => WriteOffReason::PhysicalWear]], $this->now);
        $pc->saveWriteOffAct($actId, [['recordId' => $recordId, 'quantity' => 2.0, 'reason' => WriteOffReason::PhysicalWear]], $this->now); // set, не add

        self::assertCount(1, $pc->itemsOfWriteOffAct($actId));
        self::assertSame(2.0, $pc->itemsOfWriteOffAct($actId)[0]->quantity());
    }

    public function test_delete_draft_removes_act(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->signedGlovesCard($pc, $this->now, 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $actId = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);
        $pc->saveWriteOffAct($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => WriteOffReason::PhysicalWear]], $this->now);

        $pc->deleteWriteOffDraft($actId);

        self::assertCount(0, $pc->getWriteOffActs());
        self::assertSame(2.0, $pc->heldOf($this->glovesKey())); // ничего не списано
    }

    public function test_sign_without_reason_throws(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->signedGlovesCard($pc, $this->now, 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $actId = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);
        $pc->saveWriteOffAct($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => null]], $this->now);

        $this->expectException(AppException::class); // причина не указана
        $pc->signWriteOffAct($actId, $this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan-wo', $this->now);
    }

    public function test_sign_act_applies_partial_write_off(): void
    {
        $pc = $this->pcWithGloves(2.0); // норма 2
        $this->signedGlovesCard($pc, new \DateTimeImmutable('2026-06-01'), 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $actId = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);
        $pc->saveWriteOffAct($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => WriteOffReason::PhysicalWear]], $this->now);

        $pc->signWriteOffAct($actId, $this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan-wo', $this->now);
        $pc->recomputeRequirement($this->reqId, $this->calc); // пересчёт проекции — в проде async по событию

        self::assertTrue($pc->getWriteOffActs()[0]->isSigned());
        self::assertSame(1.0, $pc->heldOf($this->glovesKey())); // на руках 1 из 2 (гашение — синхронно в подписи)
        self::assertNotNull($pc->getObligations()[0]->lastFulfilledAt());

        // списываем второй (новый акт по тому же требованию)
        $actId2 = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);
        $pc->saveWriteOffAct($actId2, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => WriteOffReason::PhysicalWear]], $this->now);
        $pc->signWriteOffAct($actId2, $this->commission(), '40', new \DateTimeImmutable('2026-09-01'), 'scan-wo2', $this->now);
        $pc->recomputeRequirement($this->reqId, $this->calc);

        self::assertSame(0.0, $pc->heldOf($this->glovesKey())); // всё списано
        self::assertNull($pc->getObligations()[0]->lastFulfilledAt()); // позиция освобождена
    }

    public function test_projection_holds_quantity(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->signedGlovesCard($pc, new \DateTimeImmutable('2026-06-01'), 2.0);

        self::assertSame(2.0, $pc->getObligations()[0]->heldQuantity());
    }

    public function test_type_of_requirement_returns_mono_type(): void
    {
        $pc = $this->pcWithGloves(2.0);

        self::assertSame(ComplianceType::Material, $pc->typeOfRequirement($this->reqId));
        self::assertNull($pc->typeOfRequirement('нет-такого'));
    }

    public function test_add_personal_obligation_is_tracked_and_rejects_duplicate(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $key = $pc->addPersonalObligation(Uuid::v4(), $this->reqId, 'Очки', new Cadence(CadenceKind::ByManufacturerDoc), new Quantity(1.0, Unit::Piece));

        self::assertSame(TrackedObligation::keyOf($this->reqId, 'Очки'), $key);
        $added = array_values(array_filter($pc->getObligations(), static fn (TrackedObligation $o): bool => $o->key() === $key));
        self::assertCount(1, $added);
        self::assertSame(TrackedObligation::ORIGIN_PERSONAL, $added[0]->origin());
        self::assertSame(ComplianceType::Material, $added[0]->type()); // тип выведен от нормы акта (перчатки — материальные)

        $this->expectException(AppException::class); // дубль по тому же ключу (keyOf лоуэркейсит label)
        $pc->addPersonalObligation(Uuid::v4(), $this->reqId, 'очки', new Cadence(CadenceKind::ByManufacturerDoc), new Quantity(1.0, Unit::Piece));
    }

    private function commission(): Commission
    {
        return new Commission(
            new CommissionMember('Алиханова Н.И.', position: 'руководитель отдела ОТ и ПБ'),
            new CommissionMember('Корзун П.Е.', position: 'специалист по учету ТМЦ'),
        );
    }

    private function glovesLine(float $amount, string $issueDate = '2026-06-01'): IssuanceLine
    {
        return new IssuanceLine(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable($issueDate), new Quantity($amount, Unit::Pair));
    }

    public function test_issue_below_norm_minus_held_throws(): void
    {
        $pc = $this->pcWithGloves(2.0);
        // ничего на руках (held 0), норма 2 → выдать 1 нельзя
        $this->expectException(AppException::class);
        $pc->assertIssuable([$this->glovesLine(1.0)]);
    }

    public function test_records_for_requirement_filters_by_key(): void
    {
        $pc = $this->pcWithGloves();
        $otherReqId = 'req-2';
        $pc->putObligation(new TrackedObligation(
            Uuid::v4(), $pc, $otherReqId, 'Другое требование', 'Каска',
            ComplianceType::Material, new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year),
            new Quantity(1.0, Unit::Piece), 'dept-1',
        ));
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc, new Quantity(10.0, Unit::Pair));
        $pc->recordFulfillment(Uuid::v4(), TrackedObligation::keyOf($otherReqId, 'Каска'), new \DateTimeImmutable('2026-01-10'), $this->calc, new Quantity(1.0, Unit::Piece));

        $records = $pc->recordsForRequirement($this->reqId);

        self::assertCount(1, $records);
        self::assertSame($this->glovesKey(), $records[0]->obligationKey());
    }

    public function test_issue_covers_deficit_ok(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->signedGlovesCard($pc, new \DateTimeImmutable('2026-06-01'), 2.0); // held 2
        $recordId = $pc->getRecords()[0]->getId();
        $actId = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);
        $pc->saveWriteOffAct($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => WriteOffReason::PhysicalWear]], $this->now);
        $pc->signWriteOffAct($actId, $this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan', $this->now);
        // held 1, норма 2 → до-выдать 1 достаточно
        $pc->assertIssuable([$this->glovesLine(1.0)]); // не бросает
        self::assertSame(1.0, $pc->heldOf($this->glovesKey()));
    }
}
