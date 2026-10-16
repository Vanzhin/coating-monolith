<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Aggregate\ProfileCompliance\FulfillmentRecord;
use App\Compliance\Domain\Aggregate\ProfileCompliance\IssuanceLine;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Service\ComplianceStatusResolver;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceStatus;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\JournalKind;
use App\Compliance\Domain\Type\MaterialMode;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Instruction\InstructionDetails;
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
    private string $glovesItemId = 'item-gloves';
    private string $fireSafetyItemId = 'item-fire';
    private string $creamItemId = 'item-cream';
    private string $helmetItemId = 'item-helmet';

    protected function setUp(): void
    {
        $this->calc = new ObligationDueCalculator();
        $this->resolver = new ComplianceStatusResolver();
        $this->now = new \DateTimeImmutable('2026-06-01');
    }

    /** № карточки по умолчанию уже закреплён — большинству тестов нужна рабочая выдача, не сам инвариант номера. */
    private function pcWithGloves(float $norm = 10.0, ?string $cardNumber = 'CARD-001'): ProfileCompliance
    {
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        if (null !== $cardNumber) {
            $pc->assignCardNumber($cardNumber);
        }
        $pc->putObligation(new TrackedObligation(
            Uuid::v4(), $pc, $this->reqId, $this->glovesItemId, 'СИЗ основные', 'Перчатки',
            ComplianceType::Material, new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year),
            new Quantity($norm, Unit::Pair), 'dept-1',
        ));

        return $pc;
    }

    private function glovesKey(): string
    {
        return TrackedObligation::keyOf($this->reqId, $this->glovesItemId);
    }

    private function pcWithFireSafetyObligation(): ProfileCompliance
    {
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $pc->assignCardNumber('CARD-001');
        $pc->putObligation(new TrackedObligation(
            Uuid::v4(), $pc, 'req-2', $this->fireSafetyItemId, 'Пожарная безопасность', 'Пожарный',
            ComplianceType::NonMaterial, new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year),
            null, 'dept-1',
        ));

        return $pc;
    }

    private function fireSafetyKey(): string
    {
        return TrackedObligation::keyOf('req-2', $this->fireSafetyItemId);
    }

    /** Выдать перчатки прямой записью в журнал (журнальная замена «подписанного акта» в тестах списания). */
    private function issuedGloves(ProfileCompliance $pc, \DateTimeImmutable $at, float $amount = 10.0): void
    {
        $pc->recordIssuance($this->calc, $this->glovesLine($amount, $at->format('Y-m-d')));
    }

    /** Перчатки (инвентарь, held>0) + Крем защитный (расходная ДСИЗ, held>0) — обе того же требования. */
    private function pcWithGlovesAndConsumableCream(): ProfileCompliance
    {
        $pc = $this->pcWithGloves(10.0);
        $pc->putObligation(new TrackedObligation(
            Uuid::v4(), $pc, $this->reqId, $this->creamItemId, 'СИЗ основные', 'Крем защитный',
            ComplianceType::Material, new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year),
            new Quantity(3.0, Unit::Pair), 'dept-1', TrackedObligation::ORIGIN_NORM, MaterialMode::Consumable,
        ));
        $this->issuedGloves($pc, $this->now, 10.0);
        $pc->recordIssuance($this->calc, new IssuanceLine(Uuid::v4(), $this->creamKey(), $this->now, new Quantity(3.0, Unit::Pair)));

        return $pc;
    }

    private function creamKey(): string
    {
        return TrackedObligation::keyOf($this->reqId, $this->creamItemId);
    }

    /** Перчатки + Каска — обе инвентарные (mode=null), того же требования, обе выданы. */
    private function pcWithGlovesAndHelmet(): ProfileCompliance
    {
        $pc = $this->pcWithGloves(10.0);
        $pc->putObligation(new TrackedObligation(
            Uuid::v4(), $pc, $this->reqId, $this->helmetItemId, 'СИЗ основные', 'Каска',
            ComplianceType::Material, new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year),
            new Quantity(1.0, Unit::Piece), 'dept-1',
        ));
        $this->issuedGloves($pc, $this->now, 10.0);
        $pc->recordIssuance($this->calc, new IssuanceLine(Uuid::v4(), $this->helmetKey(), $this->now, new Quantity(1.0, Unit::Piece)));

        return $pc;
    }

    private function helmetKey(): string
    {
        return TrackedObligation::keyOf($this->reqId, $this->helmetItemId);
    }

    public function test_fulfillment_recomputes_dates(): void
    {
        $pc = $this->pcWithGloves();
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc);

        $ob = $pc->getObligations()[0];
        self::assertSame('2026-01-10', $ob->lastFulfilledAt()?->format('Y-m-d'));
        self::assertSame('2027-01-10', $ob->nextDueAt()?->format('Y-m-d'));
    }

    public function test_fulfilled_far_is_green(): void
    {
        $pc = $this->pcWithGloves();
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc, new Quantity(10.0, Unit::Pair));

        // Запись факта синхронно включила трекинг (active) — отдельная пересборка не нужна.
        self::assertSame(ComplianceStatus::Green, $pc->worstStatus($this->resolver, null, $this->now));
    }

    public function test_removing_last_record_rolls_back_dates_and_status(): void
    {
        $pc = $this->pcWithGloves();
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc);

        $recId = $pc->getRecords()[0]->getId();
        $pc->removeRecord($recId, $this->calc);

        self::assertNull($pc->getObligations()[0]->lastFulfilledAt());
        self::assertNull($pc->getObligations()[0]->nextDueAt());
        self::assertFalse($pc->getObligations()[0]->isActive(), 'факта не осталось → трекинг снят синхронно');
        self::assertSame(ComplianceStatus::Red, $pc->worstStatus($this->resolver, null, $this->now)); // нет выданного факта
    }

    public function test_type_filter_in_worst_status(): void
    {
        $pc = $this->pcWithGloves();
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

    public function test_start_returns_same_open_write_off_draft(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->issuedGloves($pc, new \DateTimeImmutable('2026-06-01'), 2.0);

        $a1 = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);
        $a2 = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);

        self::assertSame($a1, $a2); // один открытый черновик списания
        self::assertCount(1, $pc->getWriteOffActs());
    }

    public function test_start_write_off_requires_held_fact_not_document(): void
    {
        $pc = $this->pcWithGloves(2.0); // норма 2
        $pc->recordIssuance($this->calc, $this->glovesLine(2.0)); // факт журнала напрямую

        $actId = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);

        self::assertNotSame('', $actId, 'гард смотрит на выданный факт журнала');
    }

    public function test_start_write_off_without_held_fact_throws(): void
    {
        $pc = $this->pcWithGloves(2.0); // норма есть, но ни одного факта не выдано

        $this->expectException(AppException::class);
        $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);
    }

    public function test_save_portions_without_effect(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->issuedGloves($pc, new \DateTimeImmutable('2026-06-01'), 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $actId = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);

        $pc->saveWriteOffAct($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => WriteOffReason::PhysicalWear]], $this->now);

        // эффекта нет — на руках всё ещё 2
        self::assertSame(2.0, $pc->heldOf($this->glovesKey()));
        self::assertCount(1, $pc->itemsOfWriteOffAct($actId));
        self::assertSame(1.0, $pc->itemsOfWriteOffAct($actId)[0]->quantity());
    }

    public function test_save_more_than_held_throws(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->issuedGloves($pc, $this->now, 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $actId = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);

        $this->expectException(AppException::class);
        $pc->saveWriteOffAct($actId, [['recordId' => $recordId, 'quantity' => 3.0, 'reason' => WriteOffReason::PhysicalWear]], $this->now);
    }

    public function test_save_replaces_portions(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->issuedGloves($pc, $this->now, 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $actId = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);

        $pc->saveWriteOffAct($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => WriteOffReason::PhysicalWear]], $this->now);
        $pc->saveWriteOffAct($actId, [['recordId' => $recordId, 'quantity' => 2.0, 'reason' => WriteOffReason::PhysicalWear]], $this->now); // set, не add

        self::assertCount(1, $pc->itemsOfWriteOffAct($actId));
        self::assertSame(2.0, $pc->itemsOfWriteOffAct($actId)[0]->quantity());
    }

    public function test_delete_write_off_draft_removes_act(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->issuedGloves($pc, $this->now, 2.0);
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
        $this->issuedGloves($pc, $this->now, 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $actId = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);
        $pc->saveWriteOffAct($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => null]], $this->now);

        $this->expectException(AppException::class); // причина не указана
        $pc->signWriteOffAct($actId, $this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan-wo', $this->now);
    }

    public function test_sign_act_applies_partial_write_off(): void
    {
        $pc = $this->pcWithGloves(2.0); // норма 2
        $this->issuedGloves($pc, new \DateTimeImmutable('2026-06-01'), 2.0);
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

    public function test_remove_issuance_record_cascades_to_write_off(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->issuedGloves($pc, new \DateTimeImmutable('2026-06-01'), 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $actId = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);
        $pc->saveWriteOffAct($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => WriteOffReason::PhysicalWear]], $this->now);
        $pc->signWriteOffAct($actId, $this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan-wo', $this->now);

        $result = $pc->removeIssuanceRecord(Uuid::fromString($recordId), $this->calc);

        self::assertSame($this->reqId, $result['requirementId']);
        self::assertContains('scan-wo', $result['fileIds'], 'скан опустевшего акта списания возвращён на зачистку');
        self::assertNull($pc->recordById($recordId), 'факт журнала удалён');
        self::assertCount(0, $pc->getWriteOffActs(), 'опустевший акт списания удалён вместе с фактом');
    }

    public function test_remove_issuance_record_works_after_obligation_excluded(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $pc->recordIssuance($this->calc, $this->glovesLine(2.0)); // прямая дозапись
        $recordId = $pc->getRecords()[0]->getId();

        $pc->excludeObligation($this->glovesKey()); // обязанность убрана из проекции, факт остался

        $result = $pc->removeIssuanceRecord(Uuid::fromString($recordId), $this->calc); // не должно бросать

        self::assertSame($this->reqId, $result['requirementId'], 'requirementId резолвится из ключа факта, а не из проекции');
        self::assertSame([], $result['fileIds']);
        self::assertNull($pc->recordById($recordId), 'факт журнала удалён');
    }

    public function test_projection_holds_quantity(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->issuedGloves($pc, new \DateTimeImmutable('2026-06-01'), 2.0);

        self::assertSame(2.0, $pc->getObligations()[0]->heldQuantity());
    }

    public function test_type_of_requirement_returns_mono_type(): void
    {
        $pc = $this->pcWithGloves(2.0);

        self::assertSame(ComplianceType::Material, $pc->typeOfRequirement($this->reqId));
        self::assertNull($pc->typeOfRequirement('нет-такого'));
    }

    public function test_add_personal_obligation_is_tracked(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $itemId = Uuid::v4()->toRfc4122();
        $key = $pc->addPersonalObligation(Uuid::v4(), $itemId, $this->reqId, 'Очки', new Cadence(CadenceKind::ByManufacturerDoc), new Quantity(1.0, Unit::Piece));

        self::assertSame(TrackedObligation::keyOf($this->reqId, $itemId), $key); // ключ = reqId|itemId кандидата
        $added = array_values(array_filter($pc->getObligations(), static fn (TrackedObligation $o): bool => $o->key() === $key));
        self::assertCount(1, $added);
        self::assertSame(TrackedObligation::ORIGIN_PERSONAL, $added[0]->origin());
        self::assertSame(ComplianceType::Material, $added[0]->type()); // тип выведен от нормы акта (перчатки — материальные)
    }

    public function test_personal_obligation_dedup_by_label_returns_same_key(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $k1 = $pc->addPersonalObligation(Uuid::v4(), Uuid::v4()->toRfc4122(), $this->reqId, 'Фартук', new Cadence(CadenceKind::ByManufacturerDoc), null);
        $k2 = $pc->addPersonalObligation(Uuid::v4(), Uuid::v4()->toRfc4122(), $this->reqId, 'ФАРТУК', new Cadence(CadenceKind::ByManufacturerDoc), null);

        self::assertSame($k1, $k2, 'повторная персональная с тем же label (регистронезависимо) — та же обязанность');
        $added = array_values(array_filter($pc->getObligations(), static fn (TrackedObligation $o): bool => $o->key() === $k1));
        self::assertCount(1, $added, 'кандидаты второго вызова проигнорированы — одна позиция');
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

    public function test_issue_with_unit_other_than_norm_throws(): void
    {
        $pc = $this->pcWithGloves(10.0); // норма 10 пар
        $this->expectException(AppException::class); // «шт.» вместо «пар» — единица обязана совпадать с нормой
        $pc->assertIssuable([new IssuanceLine(Uuid::v4(), $this->glovesKey(), $this->now, new Quantity(10.0, Unit::Piece))]);
    }

    public function test_records_for_requirement_filters_by_key(): void
    {
        $pc = $this->pcWithGloves();
        $otherReqId = 'req-2';
        $kaskaItemId = 'item-kaska';
        $pc->putObligation(new TrackedObligation(
            Uuid::v4(), $pc, $otherReqId, $kaskaItemId, 'Другое требование', 'Каска',
            ComplianceType::Material, new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year),
            new Quantity(1.0, Unit::Piece), 'dept-1',
        ));
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc, new Quantity(10.0, Unit::Pair));
        $pc->recordFulfillment(Uuid::v4(), TrackedObligation::keyOf($otherReqId, $kaskaItemId), new \DateTimeImmutable('2026-01-10'), $this->calc, new Quantity(1.0, Unit::Piece));

        $records = $pc->recordsForRequirement($this->reqId);

        self::assertCount(1, $records);
        self::assertSame($this->glovesKey(), $records[0]->obligationKey());
    }

    public function test_issue_covers_deficit_ok(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->issuedGloves($pc, new \DateTimeImmutable('2026-06-01'), 2.0); // held 2
        $recordId = $pc->getRecords()[0]->getId();
        $actId = $pc->startOrGetWriteOffDraft(Uuid::v4(), $this->reqId, $this->now);
        $pc->saveWriteOffAct($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => WriteOffReason::PhysicalWear]], $this->now);
        $pc->signWriteOffAct($actId, $this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan', $this->now);
        // held 1, норма 2 → до-выдать 1 достаточно
        $pc->assertIssuable([$this->glovesLine(1.0)]); // не бросает
        self::assertSame(1.0, $pc->heldOf($this->glovesKey()));
    }

    public function test_record_issuance_appends_journal_lines_and_recomputes(): void
    {
        $pc = $this->pcWithGloves(2.0); // норма 2
        $pc->recordIssuance($this->calc, $this->glovesLine(2.0));

        self::assertSame(2.0, $pc->heldOf($this->glovesKey()), 'прямая дозапись сразу считается выданной');
        self::assertNotNull($pc->getObligations()[0]->lastFulfilledAt(), 'пересчёт обязанности отработал');
    }

    public function test_record_issuance_activates_obligation_synchronously(): void
    {
        $pc = $this->pcWithGloves(2.0); // норма 2, на руках 0 — без факта трекинг выключен → Red
        self::assertFalse($pc->getObligations()[0]->isActive());
        self::assertSame(ComplianceStatus::Red, $pc->worstStatus($this->resolver, null, $this->now));

        $pc->recordIssuance($this->calc, $this->glovesLine(2.0));

        // Запись факта синхронно включает трекинг и наполняет норму — без отдельной пересборки проекции.
        self::assertTrue($pc->getObligations()[0]->isActive(), 'active включился сразу при записи факта');
        self::assertSame(ComplianceStatus::Green, $pc->worstStatus($this->resolver, null, $this->now), 'светофор зелёный без rebuild');
    }

    public function test_record_issuance_below_norm_throws(): void
    {
        $pc = $this->pcWithGloves(2.0); // норма 2, на руках 0
        $this->expectException(AppException::class);
        $pc->recordIssuance($this->calc, $this->glovesLine(1.0)); // 1 < нормы
    }

    public function test_record_instruction_requires_complete_fields(): void
    {
        $pc = $this->pcWithFireSafetyObligation();
        $this->expectException(AppException::class);
        $pc->recordInstruction(
            $this->calc, JournalKind::FireSafety,
            new IssuanceLine(Uuid::v4(), $this->fireSafetyKey(), new \DateTimeImmutable('2026-01-10'), instructionDetails: InstructionDetails::fromArray([])),
        );
    }

    public function test_has_issued_fact_for_no_record_is_false(): void
    {
        $pc = $this->pcWithGloves();
        self::assertFalse($pc->hasIssuedFactFor($this->glovesKey()));
    }

    public function test_has_issued_fact_for_direct_issuance_is_true(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $pc->recordIssuance($this->calc, $this->glovesLine(2.0));

        self::assertTrue($pc->hasIssuedFactFor($this->glovesKey()), 'прямая дозапись — выданный факт');
    }

    public function test_record_issuance_without_card_number_throws(): void
    {
        $pc = $this->pcWithGloves(2.0, cardNumber: null); // № карточки не закреплён
        $this->expectException(AppException::class);
        $pc->recordIssuance($this->calc, $this->glovesLine(2.0)); // количество само по себе достаточно
    }

    public function test_assign_card_number_is_set_once(): void
    {
        $pc = $this->pcWithGloves(2.0, cardNumber: null);
        $pc->assignCardNumber('CARD-100');
        $pc->assignCardNumber('CARD-200'); // повторная попытка сменить — no-op, номер уже закреплён

        self::assertSame('CARD-100', $pc->cardNumber());
    }

    public function test_assign_card_number_ignores_blank_value(): void
    {
        $pc = $this->pcWithGloves(2.0, cardNumber: null);
        $pc->assignCardNumber('   '); // пусто после trim — номером не считается

        self::assertNull($pc->cardNumber());
    }

    public function test_record_issuance_succeeds_after_card_number_assigned(): void
    {
        $pc = $this->pcWithGloves(2.0, cardNumber: null);
        $pc->assignCardNumber('CARD-1');

        $pc->recordIssuance($this->calc, $this->glovesLine(2.0));

        self::assertSame('CARD-1', $pc->cardNumber());
        self::assertSame(2.0, $pc->heldOf($this->glovesKey()));
    }

    public function test_apply_write_off_quenches_held_quantity_of_own_record(): void
    {
        $pc = $this->pcWithGloves(10.0);
        $this->issuedGloves($pc, $this->now, 10.0);
        $recordId = $pc->getRecords()[0]->getId();

        $pc->applyWriteOff([$recordId => 4.0]);

        self::assertEqualsWithDelta(6.0, $pc->heldOf($this->glovesKey()), 1e-9, 'на руках уменьшилось на списанное количество');
    }

    public function test_apply_write_off_handles_several_records_by_id(): void
    {
        $pc = $this->pcWithGloves(10.0);
        $this->issuedGloves($pc, $this->now, 10.0); // первый факт, held 10
        $firstRecordId = $pc->getRecords()[0]->getId();
        $pc->recordIssuance($this->calc, $this->glovesLine(5.0, '2026-07-01')); // второй факт, held 5
        $secondRecordId = $pc->getRecords()[1]->getId();

        $pc->applyWriteOff([$firstRecordId => 10.0, $secondRecordId => 2.0]);

        self::assertEqualsWithDelta(0.0, $pc->getRecords()[0]->heldAmount(), 1e-9);
        self::assertEqualsWithDelta(3.0, $pc->getRecords()[1]->heldAmount(), 1e-9);
    }

    public function test_apply_write_off_ignores_unknown_record_id(): void
    {
        $pc = $this->pcWithGloves(10.0);
        $this->issuedGloves($pc, $this->now, 10.0);

        $pc->applyWriteOff(['нет-такой-записи' => 5.0]); // не должно бросать — акт кросс-персон, не вся строка «своя»

        self::assertEqualsWithDelta(10.0, $pc->heldOf($this->glovesKey()), 1e-9, 'held не изменился');
    }

    public function test_write_off_eligible_facts_includes_inventory_held_position(): void
    {
        $pc = $this->pcWithGloves(10.0);
        $this->issuedGloves($pc, $this->now, 10.0);

        $eligible = $pc->writeOffEligibleFactsForRequirement($this->reqId, null);

        self::assertCount(1, $eligible);
        self::assertSame($this->glovesKey(), $eligible[0]->obligationKey());
    }

    public function test_write_off_eligible_facts_excludes_consumable_position_when_selecting_all(): void
    {
        $pc = $this->pcWithGlovesAndConsumableCream();

        $eligible = $pc->writeOffEligibleFactsForRequirement($this->reqId, null);

        self::assertCount(1, $eligible, '«Списать всё» не берёт расходную (ДСИЗ) позицию');
        self::assertSame($this->glovesKey(), $eligible[0]->obligationKey());
    }

    public function test_write_off_eligible_facts_ignores_explicit_consumable_key(): void
    {
        $pc = $this->pcWithGlovesAndConsumableCream();

        $eligible = $pc->writeOffEligibleFactsForRequirement($this->reqId, [$this->glovesKey(), $this->creamKey()]);

        self::assertCount(1, $eligible, 'явный выбор расходной позиции всё равно отфильтрован — бэк источник истины');
        self::assertSame($this->glovesKey(), $eligible[0]->obligationKey());
    }

    public function test_write_off_eligible_facts_excludes_non_material_fact(): void
    {
        $pc = $this->pcWithFireSafetyObligation();
        $pc->recordFulfillment(Uuid::v4(), $this->fireSafetyKey(), $this->now, $this->calc); // нематериальный факт, quantity нет

        $eligible = $pc->writeOffEligibleFactsForRequirement('req-2', null);

        self::assertSame([], $eligible, 'нематериальный факт отсекается own held=0, списанию не подлежит');
    }

    public function test_write_off_eligible_facts_filters_by_explicit_keys(): void
    {
        $pc = $this->pcWithGlovesAndHelmet();

        $eligible = $pc->writeOffEligibleFactsForRequirement($this->reqId, [$this->glovesKey()]);

        self::assertCount(1, $eligible, 'явный выбор ограничивает до запрошенного ключа — вторая held-позиция (каска) не попала');
        self::assertSame($this->glovesKey(), $eligible[0]->obligationKey());
    }

    public function test_write_off_eligible_facts_includes_personal_obligation_with_null_mode(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $itemId = Uuid::v4()->toRfc4122();
        $key = $pc->addPersonalObligation(Uuid::v4(), $itemId, $this->reqId, 'Очки', new Cadence(CadenceKind::ByManufacturerDoc), new Quantity(1.0, Unit::Piece));
        $pc->recordIssuance($this->calc, new IssuanceLine(Uuid::v4(), $key, $this->now, new Quantity(1.0, Unit::Piece), manualDueDate: new \DateTimeImmutable('2027-06-01')));

        $eligible = $pc->writeOffEligibleFactsForRequirement($this->reqId, null);

        $keys = array_map(static fn (FulfillmentRecord $f): string => $f->obligationKey(), $eligible);
        self::assertContains($key, $keys, 'персональная позиция без режима (mode=null) трактуется как инвентарь');
    }
}
