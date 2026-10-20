<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Aggregate\ProfileCompliance\FulfillmentRecord;
use App\Compliance\Domain\Aggregate\ProfileCompliance\IssuanceLine;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\Requirement\Requirement;
use App\Compliance\Domain\Event\FulfillmentRecordRemoved;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\JournalKind;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Instruction\InstructionDetails;
use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Compliance\Domain\ValueObject\Item\PeriodicItem;
use App\Compliance\Domain\ValueObject\ObligationKey;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Норма (Вариант B) агрегатом не материализуется — она передаётся write-инвариантам (`recordIssuance`/
 * `assertIssuable`/`writeOffEligibleFactsForRequirement`/`obligationLabelOf`) параметром {@see Requirement}.
 * requirementId фиксирован на реальный Uuid ({@see self::$reqId}), чтобы ключи обязанностей (`reqId|itemId`)
 * совпадали с `$requirement->getId()`. Даты/held/active/статус read-модели считает проектор — покрыто
 * `ComplianceObligationProjectorTest`/`ObligationProjectionRepositoryTest`, здесь не дублируется.
 */
final class ProfileComplianceTest extends TestCase
{
    private \DateTimeImmutable $now;
    private string $reqId;
    private string $periodicReqId;
    private string $glovesItemId = 'item-gloves';
    private string $fireSafetyItemId = 'item-fire';
    private string $creamItemId = 'item-cream';
    private string $soapItemId = 'item-soap';
    private string $helmetItemId = 'item-helmet';

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-06-01');
        $this->reqId = (string) Uuid::v4();
        $this->periodicReqId = (string) Uuid::v4();
    }

    /** № карточки по умолчанию уже закреплён — большинству тестов нужна рабочая выдача, не сам инвариант номера. */
    private function pcWithGloves(?string $cardNumber = 'CARD-001'): ProfileCompliance
    {
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        if (null !== $cardNumber) {
            $pc->assignCardNumber($cardNumber);
        }

        return $pc;
    }

    private function glovesKey(): string
    {
        return ObligationKey::keyOf($this->reqId, $this->glovesItemId);
    }

    /** Требование с одной материальной позицией (Перчатки, норма $norm пар, периодичность 1 год). */
    private function glovesRequirement(float $norm = 10.0): Requirement
    {
        return new Requirement(
            Uuid::fromString($this->reqId), 'СИЗ основные', ComplianceType::Material, new StringCollection(),
            new MaterialItem($this->glovesItemId, 'Перчатки', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), 'п.5', new Quantity($norm, Unit::Pair)),
        );
    }

    /**
     * Периодическое требование (бывш. расходная ДСИЗ, теперь PeriodicItem — остаток не ведётся, списанию не
     * подлежит). Требования мономорфны (Requirement::supports — однотипные позиции), поэтому после снятия
     * MaterialMode инвентарная и периодическая позиция больше не могут жить в ОДНОМ требовании — отдельный
     * Requirement, свой reqId.
     */
    private function periodicCreamRequirement(): Requirement
    {
        return new Requirement(
            Uuid::fromString($this->periodicReqId), 'ДСИЗ', ComplianceType::Periodic, new StringCollection(),
            new PeriodicItem($this->creamItemId, 'Крем защитный', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), 'п.7', new Quantity(3.0, Unit::Pair)),
        );
    }

    private function creamKeyOnPeriodicRequirement(): string
    {
        return ObligationKey::keyOf($this->periodicReqId, $this->creamItemId);
    }

    /**
     * Периодический расходник (НЕ инструктаж) с нормой в «мл» — для Finding 2+4: единицу теперь сверяем и у
     * периодической, недовыдача остаётся допустимой (не блокирует assertIssuable).
     */
    private function periodicSoapRequirement(float $norm = 1200.0): Requirement
    {
        return new Requirement(
            Uuid::fromString($this->periodicReqId), 'ДСИЗ', ComplianceType::Periodic, new StringCollection(),
            new PeriodicItem($this->soapItemId, 'Мыло', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), 'п.7', new Quantity($norm, Unit::Milliliter)),
        );
    }

    private function soapKeyOnPeriodicRequirement(): string
    {
        return ObligationKey::keyOf($this->periodicReqId, $this->soapItemId);
    }

    /** То же требование + Каска (инвентарная). */
    private function glovesAndHelmetRequirement(float $glovesNorm = 10.0): Requirement
    {
        return new Requirement(
            Uuid::fromString($this->reqId), 'СИЗ основные', ComplianceType::Material, new StringCollection(),
            new MaterialItem($this->glovesItemId, 'Перчатки', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), 'п.5', new Quantity($glovesNorm, Unit::Pair)),
            new MaterialItem($this->helmetItemId, 'Каска', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), 'п.9', new Quantity(1.0, Unit::Piece)),
        );
    }

    private function fireSafetyRequirement(): Requirement
    {
        return new Requirement(
            Uuid::fromString($this->reqId), 'Пожарная безопасность', ComplianceType::Periodic, new StringCollection(),
            new PeriodicItem($this->fireSafetyItemId, 'Пожарный', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), 'п.1', new Quantity(1.0, Unit::Piece)),
        );
    }

    private function fireSafetyKey(): string
    {
        return ObligationKey::keyOf($this->reqId, $this->fireSafetyItemId);
    }

    /** Выдать перчатки прямой записью в журнал. */
    private function issuedGloves(ProfileCompliance $pc, Requirement $requirement, \DateTimeImmutable $at, float $amount = 10.0): void
    {
        $pc->recordIssuance($requirement, $this->glovesLine($amount, $at->format('Y-m-d')));
    }

    private function helmetKey(): string
    {
        return ObligationKey::keyOf($this->reqId, $this->helmetItemId);
    }

    private function glovesLine(float $amount, string $issueDate = '2026-06-01'): IssuanceLine
    {
        return new IssuanceLine(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable($issueDate), new Quantity($amount, Unit::Pair));
    }

    public function test_exclude_adds_key_to_excluded_list(): void
    {
        $pc = $this->pcWithGloves();
        $pc->excludeObligation($this->glovesKey());

        self::assertContains($this->glovesKey(), $pc->getExcludedKeys()->getList());
    }

    public function test_exclude_is_idempotent(): void
    {
        $pc = $this->pcWithGloves();
        $pc->excludeObligation($this->glovesKey());
        $pc->excludeObligation($this->glovesKey());

        self::assertCount(1, $pc->getExcludedKeys()->getList());
    }

    public function test_remove_issuance_record_raises_fulfillment_record_removed_event(): void
    {
        $requirement = $this->glovesRequirement(2.0);
        $pc = $this->pcWithGloves();
        $pc->recordIssuance($requirement, $this->glovesLine(2.0, '2026-06-01'));
        $recordId = $pc->getRecords()[0]->getId();

        $requirementId = $pc->removeIssuanceRecord(Uuid::fromString($recordId));

        self::assertSame($this->reqId, $requirementId);
        self::assertNull($pc->recordById($recordId), 'факт журнала удалён');
        // Каскад снятия порций с черновиков акта списания — отдельный кросс-персон агрегат: эмитим событие,
        // его ловит DetachRemovedRecordFromWriteOffsHandler (покрыт функционально).
        $events = $pc->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(FulfillmentRecordRemoved::class, $events[0]);
        self::assertSame('prof-1', $events[0]->profileId);
        self::assertSame($recordId, $events[0]->recordId);
    }

    public function test_remove_issuance_record_works_after_obligation_excluded(): void
    {
        $requirement = $this->glovesRequirement(2.0);
        $pc = $this->pcWithGloves();
        $pc->recordIssuance($requirement, $this->glovesLine(2.0));
        $recordId = $pc->getRecords()[0]->getId();

        $pc->excludeObligation($this->glovesKey()); // личное отклонение — запись журнала не трогает

        $requirementId = $pc->removeIssuanceRecord(Uuid::fromString($recordId)); // не должно бросать

        self::assertSame($this->reqId, $requirementId, 'requirementId резолвится из ключа факта');
        self::assertNull($pc->recordById($recordId), 'факт журнала удалён');
    }

    public function test_add_personal_obligation_is_tracked(): void
    {
        $pc = $this->pcWithGloves();
        $itemId = Uuid::v4()->toRfc4122();
        $key = $pc->addPersonalObligation($itemId, $this->reqId, 'Очки', ComplianceType::Material, new Cadence(CadenceKind::ByManufacturerDoc), new Quantity(1.0, Unit::Piece));

        self::assertSame(ObligationKey::keyOf($this->reqId, $itemId), $key);
        $added = array_values(array_filter($pc->personalObligations(), static fn ($o): bool => $o->itemId === $itemId));
        self::assertCount(1, $added);
        self::assertSame('Очки', $added[0]->label);
        self::assertSame(ComplianceType::Material, $added[0]->type);
    }

    public function test_personal_obligation_dedup_by_label_returns_same_key(): void
    {
        $pc = $this->pcWithGloves();
        $k1 = $pc->addPersonalObligation(Uuid::v4()->toRfc4122(), $this->reqId, 'Фартук', ComplianceType::Material, new Cadence(CadenceKind::ByManufacturerDoc), null);
        $k2 = $pc->addPersonalObligation(Uuid::v4()->toRfc4122(), $this->reqId, 'ФАРТУК', ComplianceType::Material, new Cadence(CadenceKind::ByManufacturerDoc), null);

        self::assertSame($k1, $k2, 'повторная персональная с тем же label (регистронезависимо) — та же обязанность');
        self::assertCount(1, $pc->personalObligations(), 'кандидаты второго вызова проигнорированы — одна позиция');
    }

    public function test_prune_orphan_personal_obligation_after_record_removed(): void
    {
        $pc = $this->pcWithGloves();
        $key = $pc->addPersonalObligation(Uuid::v4()->toRfc4122(), $this->reqId, 'Очки', ComplianceType::Material, new Cadence(CadenceKind::ByManufacturerDoc), new Quantity(1.0, Unit::Piece));
        $pc->recordFulfillment(Uuid::v4(), $key, $this->now, new Quantity(1.0, Unit::Piece));
        $recordId = $pc->getRecords()[0]->getId();

        $pc->removeIssuanceRecord(Uuid::fromString($recordId));

        self::assertCount(0, $pc->personalObligations(), 'без единого факта персональная декларация — фантом, прунится');
    }

    public function test_personal_obligation_survives_while_other_record_remains(): void
    {
        $pc = $this->pcWithGloves();
        $key = $pc->addPersonalObligation(Uuid::v4()->toRfc4122(), $this->reqId, 'Очки', ComplianceType::Material, new Cadence(CadenceKind::ByManufacturerDoc), new Quantity(1.0, Unit::Piece));
        $pc->recordFulfillment(Uuid::v4(), $key, $this->now, new Quantity(1.0, Unit::Piece));
        $pc->recordFulfillment(Uuid::v4(), $key, $this->now->modify('+1 day'), new Quantity(1.0, Unit::Piece));
        $firstRecordId = $pc->getRecords()[0]->getId();

        $pc->removeIssuanceRecord(Uuid::fromString($firstRecordId));

        self::assertCount(1, $pc->personalObligations(), 'остался ещё один факт по этой же позиции — не фантом');
    }

    public function test_issue_below_norm_minus_held_throws(): void
    {
        $requirement = $this->glovesRequirement(2.0);
        $pc = $this->pcWithGloves();
        // ничего на руках (held 0), норма 2 → выдать 1 нельзя
        $this->expectException(AppException::class);
        $pc->assertIssuable($requirement, [$this->glovesLine(1.0)]);
    }

    public function test_issue_with_unit_other_than_norm_throws(): void
    {
        $requirement = $this->glovesRequirement(10.0); // норма 10 пар
        $pc = $this->pcWithGloves();
        $this->expectException(AppException::class); // «шт.» вместо «пар» — единица обязана совпадать с нормой
        $pc->assertIssuable($requirement, [new IssuanceLine(Uuid::v4(), $this->glovesKey(), $this->now, new Quantity(10.0, Unit::Piece))]);
    }

    /**
     * Finding 2+4: единицу теперь сверяем и у периодической позиции (раньше проверка была material-only и
     * пропускала периодическую молча) — норма «мл», выдача «шт.» не проходит.
     */
    public function test_periodic_issue_with_unit_other_than_norm_throws(): void
    {
        $requirement = $this->periodicSoapRequirement(); // норма 1200 мл
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $pc->assignCardNumber('CARD-001');

        $this->expectException(AppException::class);
        $pc->assertIssuable($requirement, [new IssuanceLine(Uuid::v4(), $this->soapKeyOnPeriodicRequirement(), $this->now, new Quantity(200.0, Unit::Piece))]);
    }

    /**
     * Finding 2+4: недовыдача ниже нормы у периодической НЕ блокирует выдачу — остаток не ведётся, красный
     * цвет на дашборде обеспечивает ComplianceBucketResolver, не отказ здесь (блок — только у инвентаря).
     */
    public function test_periodic_issue_under_norm_does_not_throw(): void
    {
        $requirement = $this->periodicSoapRequirement(); // норма 1200 мл
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $pc->assignCardNumber('CARD-001');

        $pc->assertIssuable($requirement, [new IssuanceLine(Uuid::v4(), $this->soapKeyOnPeriodicRequirement(), $this->now, new Quantity(200.0, Unit::Milliliter))]); // 200 < 1200 — не бросает

        $this->addToAssertionCount(1);
    }

    public function test_assert_issuable_ignores_key_outside_requirement_and_personal(): void
    {
        $requirement = $this->glovesRequirement(2.0);
        $pc = $this->pcWithGloves();
        // Ключ принадлежит требованию, но не найден ни в норме, ни в персональных — пропускается (не бросает).
        $pc->assertIssuable($requirement, [new IssuanceLine(Uuid::v4(), ObligationKey::keyOf($this->reqId, 'нет-такой-позиции'), $this->now)]);

        $this->addToAssertionCount(1); // не бросило — это и есть ассерт
    }

    public function test_assert_issuable_requires_manual_due_date_for_personal_by_manufacturer_doc(): void
    {
        $requirement = $this->glovesRequirement(2.0);
        $pc = $this->pcWithGloves();
        $key = $pc->addPersonalObligation(Uuid::v4()->toRfc4122(), $this->reqId, 'Очки', ComplianceType::Material, new Cadence(CadenceKind::ByManufacturerDoc), new Quantity(1.0, Unit::Piece));

        $this->expectException(AppException::class);
        $pc->assertIssuable($requirement, [new IssuanceLine(Uuid::v4(), $key, $this->now, new Quantity(1.0, Unit::Piece))]);
    }

    public function test_records_for_requirement_filters_by_key(): void
    {
        $pc = $this->pcWithGloves();
        $otherReqId = (string) Uuid::v4();
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), new Quantity(10.0, Unit::Pair));
        $pc->recordFulfillment(Uuid::v4(), ObligationKey::keyOf($otherReqId, 'item-kaska'), new \DateTimeImmutable('2026-01-10'), new Quantity(1.0, Unit::Piece));

        $records = $pc->recordsForRequirement($this->reqId);

        self::assertCount(1, $records);
        self::assertSame($this->glovesKey(), $records[0]->obligationKey());
    }

    public function test_issue_covers_deficit_ok(): void
    {
        $requirement = $this->glovesRequirement(2.0);
        $pc = $this->pcWithGloves();
        $this->issuedGloves($pc, $requirement, new \DateTimeImmutable('2026-06-01'), 2.0); // held 2
        $recordId = $pc->getRecords()[0]->getId();
        $pc->applyWriteOff([$recordId => 1.0]); // эффект подписанного акта списания — гашение 1 (held 2 → 1)
        // held 1, норма 2 → до-выдать 1 достаточно
        $pc->assertIssuable($requirement, [$this->glovesLine(1.0)]); // не бросает
        self::assertSame(1.0, $pc->heldOf($this->glovesKey()));
    }

    public function test_record_issuance_appends_journal_lines(): void
    {
        $requirement = $this->glovesRequirement(2.0); // норма 2
        $pc = $this->pcWithGloves();
        $pc->recordIssuance($requirement, $this->glovesLine(2.0));

        self::assertSame(2.0, $pc->heldOf($this->glovesKey()), 'прямая дозапись сразу считается выданной');
    }

    public function test_record_issuance_below_norm_throws(): void
    {
        $requirement = $this->glovesRequirement(2.0); // норма 2, на руках 0
        $pc = $this->pcWithGloves();
        $this->expectException(AppException::class);
        $pc->recordIssuance($requirement, $this->glovesLine(1.0)); // 1 < нормы
    }

    /**
     * JournalKind восстановлен для периодического требования ({@see ComplianceType::carriesJournal}) —
     * recordInstruction снова делегирует проверку полноты полей инструктажа в JournalKind::assertDetailsComplete.
     */
    public function test_record_instruction_requires_complete_fields(): void
    {
        $requirement = $this->fireSafetyRequirement();
        $requirement->setJournalKind(JournalKind::FireSafety);
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $pc->assignCardNumber('CARD-001');

        $this->expectException(AppException::class);
        $pc->recordInstruction(
            $requirement,
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
        $requirement = $this->glovesRequirement(2.0);
        $pc = $this->pcWithGloves();
        $pc->recordIssuance($requirement, $this->glovesLine(2.0));

        self::assertTrue($pc->hasIssuedFactFor($this->glovesKey()), 'прямая дозапись — выданный факт');
    }

    public function test_record_issuance_without_card_number_throws(): void
    {
        $requirement = $this->glovesRequirement(2.0);
        $pc = $this->pcWithGloves(cardNumber: null); // № карточки не закреплён
        $this->expectException(AppException::class);
        $pc->recordIssuance($requirement, $this->glovesLine(2.0)); // количество само по себе достаточно
    }

    public function test_assign_card_number_is_set_once(): void
    {
        $pc = $this->pcWithGloves(cardNumber: null);
        $pc->assignCardNumber('CARD-100');
        $pc->assignCardNumber('CARD-200'); // повторная попытка сменить — no-op, номер уже закреплён

        self::assertSame('CARD-100', $pc->cardNumber());
    }

    public function test_assign_card_number_ignores_blank_value(): void
    {
        $pc = $this->pcWithGloves(cardNumber: null);
        $pc->assignCardNumber('   '); // пусто после trim — номером не считается

        self::assertNull($pc->cardNumber());
    }

    public function test_record_issuance_succeeds_after_card_number_assigned(): void
    {
        $requirement = $this->glovesRequirement(2.0);
        $pc = $this->pcWithGloves(cardNumber: null);
        $pc->assignCardNumber('CARD-1');

        $pc->recordIssuance($requirement, $this->glovesLine(2.0));

        self::assertSame('CARD-1', $pc->cardNumber());
        self::assertSame(2.0, $pc->heldOf($this->glovesKey()));
    }

    public function test_apply_write_off_quenches_held_quantity_of_own_record(): void
    {
        $requirement = $this->glovesRequirement(10.0);
        $pc = $this->pcWithGloves();
        $this->issuedGloves($pc, $requirement, $this->now, 10.0);
        $recordId = $pc->getRecords()[0]->getId();

        $pc->applyWriteOff([$recordId => 4.0]);

        self::assertEqualsWithDelta(6.0, $pc->heldOf($this->glovesKey()), 1e-9, 'на руках уменьшилось на списанное количество');
    }

    public function test_apply_write_off_handles_several_records_by_id(): void
    {
        $requirement = $this->glovesRequirement(10.0);
        $pc = $this->pcWithGloves();
        $this->issuedGloves($pc, $requirement, $this->now, 10.0); // первый факт, held 10
        $firstRecordId = $pc->getRecords()[0]->getId();
        $pc->recordIssuance($requirement, $this->glovesLine(5.0, '2026-07-01')); // второй факт, held 5
        $secondRecordId = $pc->getRecords()[1]->getId();

        $pc->applyWriteOff([$firstRecordId => 10.0, $secondRecordId => 2.0]);

        self::assertEqualsWithDelta(0.0, $pc->getRecords()[0]->heldAmount(), 1e-9);
        self::assertEqualsWithDelta(3.0, $pc->getRecords()[1]->heldAmount(), 1e-9);
    }

    public function test_apply_write_off_ignores_unknown_record_id(): void
    {
        $requirement = $this->glovesRequirement(10.0);
        $pc = $this->pcWithGloves();
        $this->issuedGloves($pc, $requirement, $this->now, 10.0);

        $pc->applyWriteOff(['нет-такой-записи' => 5.0]); // не должно бросать — акт кросс-персон, не вся строка «своя»

        self::assertEqualsWithDelta(10.0, $pc->heldOf($this->glovesKey()), 1e-9, 'held не изменился');
    }

    public function test_write_off_eligible_facts_includes_inventory_held_position(): void
    {
        $requirement = $this->glovesRequirement(10.0);
        $pc = $this->pcWithGloves();
        $this->issuedGloves($pc, $requirement, $this->now, 10.0);

        $eligible = $pc->writeOffEligibleFactsForRequirement($requirement, null);

        self::assertCount(1, $eligible);
        self::assertSame($this->glovesKey(), $eligible[0]->obligationKey());
    }

    /**
     * CRITICAL guard (hidden write-off leak): периодическая позиция (бывш. расходная ДСИЗ) с held>0 НЕ
     * попадает в «Списать всё» — списание структурно только инвентарь (MaterialItem), не по режиму (режима
     * больше нет).
     */
    public function test_write_off_eligible_facts_excludes_periodic_held_position(): void
    {
        $requirement = $this->periodicCreamRequirement();
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $pc->assignCardNumber('CARD-001');
        $pc->recordIssuance($requirement, new IssuanceLine(Uuid::v4(), $this->creamKeyOnPeriodicRequirement(), $this->now, new Quantity(3.0, Unit::Pair)));

        $eligible = $pc->writeOffEligibleFactsForRequirement($requirement, null);

        self::assertSame([], $eligible, '«Списать всё» не берёт периодическую (бывш. расходную) позицию, хотя held>0');
    }

    public function test_write_off_eligible_facts_ignores_explicit_periodic_key(): void
    {
        $requirement = $this->periodicCreamRequirement();
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $pc->assignCardNumber('CARD-001');
        $pc->recordIssuance($requirement, new IssuanceLine(Uuid::v4(), $this->creamKeyOnPeriodicRequirement(), $this->now, new Quantity(3.0, Unit::Pair)));

        $eligible = $pc->writeOffEligibleFactsForRequirement($requirement, [$this->creamKeyOnPeriodicRequirement()]);

        self::assertSame([], $eligible, 'явный выбор периодической позиции всё равно отфильтрован — бэк источник истины');
    }

    public function test_write_off_eligible_facts_excludes_fact_without_quantity(): void
    {
        $requirement = $this->fireSafetyRequirement();
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $pc->recordFulfillment(Uuid::v4(), $this->fireSafetyKey(), $this->now); // факт без количества — held=0

        $eligible = $pc->writeOffEligibleFactsForRequirement($requirement, null);

        self::assertSame([], $eligible, 'факт без количества даёт held=0, списанию не подлежит независимо от типа позиции');
    }

    public function test_write_off_eligible_facts_filters_by_explicit_keys(): void
    {
        $requirement = $this->glovesAndHelmetRequirement(10.0);
        $pc = $this->pcWithGloves();
        $this->issuedGloves($pc, $requirement, $this->now, 10.0);
        $pc->recordIssuance($requirement, new IssuanceLine(Uuid::v4(), $this->helmetKey(), $this->now, new Quantity(1.0, Unit::Piece)));

        $eligible = $pc->writeOffEligibleFactsForRequirement($requirement, [$this->glovesKey()]);

        self::assertCount(1, $eligible, 'явный выбор ограничивает до запрошенного ключа — вторая held-позиция (каска) не попала');
        self::assertSame($this->glovesKey(), $eligible[0]->obligationKey());
    }

    /** Персональная (off-norm) декларация материального типа — физически те же СИЗ на руках, списывается наравне с нормой. */
    public function test_write_off_eligible_facts_includes_personal_material_obligation(): void
    {
        $requirement = $this->glovesRequirement(2.0);
        $pc = $this->pcWithGloves();
        $key = $pc->addPersonalObligation(Uuid::v4()->toRfc4122(), $this->reqId, 'Очки', ComplianceType::Material, new Cadence(CadenceKind::ByManufacturerDoc), new Quantity(1.0, Unit::Piece));
        $pc->recordIssuance($requirement, new IssuanceLine(Uuid::v4(), $key, $this->now, new Quantity(1.0, Unit::Piece), manualDueDate: new \DateTimeImmutable('2027-06-01')));

        $eligible = $pc->writeOffEligibleFactsForRequirement($requirement, null);

        $keys = array_map(static fn (FulfillmentRecord $f): string => $f->obligationKey(), $eligible);
        self::assertContains($key, $keys, 'персональная декларация материального типа — списанию подлежит наравне с нормой');
    }

    /** Персональная (off-norm) декларация периодического типа — списанию НЕ подлежит (структурно только инвентарь). */
    public function test_write_off_eligible_facts_excludes_personal_periodic_obligation(): void
    {
        $requirement = $this->glovesRequirement(2.0);
        $pc = $this->pcWithGloves();
        $key = $pc->addPersonalObligation(Uuid::v4()->toRfc4122(), $this->reqId, 'Доп. инструктаж', ComplianceType::Periodic, new Cadence(CadenceKind::ByManufacturerDoc), new Quantity(1.0, Unit::Piece));
        $pc->recordIssuance($requirement, new IssuanceLine(Uuid::v4(), $key, $this->now, new Quantity(1.0, Unit::Piece), manualDueDate: new \DateTimeImmutable('2027-06-01')));

        $eligible = $pc->writeOffEligibleFactsForRequirement($requirement, null);

        $keys = array_map(static fn (FulfillmentRecord $f): string => $f->obligationKey(), $eligible);
        self::assertNotContains($key, $keys, 'персональная декларация периодического типа списанию не подлежит');
    }

    public function test_obligation_label_of_resolves_norm_item_label(): void
    {
        $requirement = $this->glovesRequirement(2.0);
        $pc = $this->pcWithGloves();

        self::assertSame('Перчатки', $pc->obligationLabelOf($requirement, $this->glovesKey()));
    }

    public function test_obligation_label_of_resolves_personal_label(): void
    {
        $requirement = $this->glovesRequirement(2.0);
        $pc = $this->pcWithGloves();
        $key = $pc->addPersonalObligation(Uuid::v4()->toRfc4122(), $this->reqId, 'Очки', ComplianceType::Material, new Cadence(CadenceKind::ByManufacturerDoc), null);

        self::assertSame('Очки', $pc->obligationLabelOf($requirement, $key));
    }

    public function test_obligation_label_of_falls_back_to_label_part_not_raw_key_when_unresolved(): void
    {
        $requirement = $this->glovesRequirement(2.0);
        $pc = $this->pcWithGloves();
        $unknownKey = ObligationKey::keyOf($this->reqId, 'нет-такой-позиции');

        // orphan-ключ (позиция переименована/убрана) не резолвится → человекочитаемая часть после `|`, не сырой ключ с reqId.
        self::assertSame('нет-такой-позиции', $pc->obligationLabelOf($requirement, $unknownKey));
    }
}
