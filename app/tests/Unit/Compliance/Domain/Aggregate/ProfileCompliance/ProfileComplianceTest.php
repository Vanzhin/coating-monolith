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

    private function pcWithGloves(): ProfileCompliance
    {
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $pc->putObligation(new TrackedObligation(
            Uuid::v4(), $pc, $this->reqId, 'СИЗ основные', 'Перчатки',
            ComplianceType::Material, new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year),
            new Quantity(10.0, Unit::Pair), 'dept-1',
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

    public function test_write_off_puts_position_in_basket_without_effect(): void
    {
        $pc = $this->pcWithGloves();
        $this->signedGlovesCard($pc, new \DateTimeImmutable('2026-06-01'));

        $writeOffId = Uuid::v4();
        $pc->writeOff($writeOffId, $this->reqId, [$this->glovesKey()], new \DateTimeImmutable('2026-08-01'));

        // эффекта нет — позиция всё ещё действующая (выдача на месте)
        self::assertSame('2026-06-01', $pc->getObligations()[0]->lastFulfilledAt()?->format('Y-m-d'));
        self::assertNull($pc->openDraftFor($this->reqId)); // нового черновика выдачи пока нет
        // позиция лежит в корзине черновика акта списания
        self::assertCount(1, $pc->getWriteOffActs());
        $act = $pc->getWriteOffActs()[0];
        self::assertTrue($act->isDraft());
        $items = $pc->itemsOfWriteOffAct((string) $writeOffId);
        self::assertCount(1, $items);
        self::assertSame($this->glovesKey(), $items[0]->obligationKey());
    }

    public function test_second_write_off_appends_to_same_draft_basket(): void
    {
        $pc = $this->pcWithGloves();
        $this->signedGlovesCard($pc, $this->now);

        $pc->writeOff(Uuid::v4(), $this->reqId, [$this->glovesKey()], $this->now);
        $pc->writeOff(Uuid::v4(), $this->reqId, [$this->glovesKey()], $this->now); // дубль по факту не кладём

        self::assertCount(1, $pc->getWriteOffActs()); // один открытый черновик-корзина
        self::assertCount(1, $pc->itemsOfWriteOffAct($pc->getWriteOffActs()[0]->getId()));
    }

    public function test_cancel_write_off_item_returns_position_and_drops_empty_act(): void
    {
        $pc = $this->pcWithGloves();
        $this->signedGlovesCard($pc, new \DateTimeImmutable('2026-06-01'));
        $writeOffId = Uuid::v4();
        $pc->writeOff($writeOffId, $this->reqId, [$this->glovesKey()], $this->now);
        $recordId = $pc->itemsOfWriteOffAct((string) $writeOffId)[0]->getId();

        $pc->cancelWriteOffItem((string) $writeOffId, $recordId, $this->now);

        self::assertCount(0, $pc->getWriteOffActs()); // корзина опустела → акт удалён
        self::assertSame('2026-06-01', $pc->getObligations()[0]->lastFulfilledAt()?->format('Y-m-d')); // позиция снова действующая
    }

    public function test_sign_write_off_act_without_reason_throws(): void
    {
        $pc = $this->pcWithGloves();
        $this->signedGlovesCard($pc, $this->now);
        $writeOffId = Uuid::v4();
        $pc->writeOff($writeOffId, $this->reqId, [$this->glovesKey()], $this->now);

        $this->expectException(AppException::class); // причина не задана
        $pc->signWriteOffAct((string) $writeOffId, $this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan-wo-1', $this->now, $this->calc);
    }

    public function test_sign_write_off_act_frees_position_and_freezes(): void
    {
        $pc = $this->pcWithGloves();
        $this->signedGlovesCard($pc, new \DateTimeImmutable('2026-06-01'));
        $writeOffId = Uuid::v4();
        $pc->writeOff($writeOffId, $this->reqId, [$this->glovesKey()], $this->now);
        $recordId = $pc->itemsOfWriteOffAct((string) $writeOffId)[0]->getId();
        $pc->applyWriteOffReasons((string) $writeOffId, [$recordId => WriteOffReason::PhysicalWear], $this->now);

        $pc->signWriteOffAct((string) $writeOffId, $this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan-wo-1', $this->now, $this->calc);

        $act = $pc->getWriteOffActs()[0];
        self::assertTrue($act->isSigned());
        self::assertSame('39', $act->actNumber());
        self::assertNotNull($act->commission());
        // эффект наступил ТОЛЬКО сейчас — позиция освобождена
        self::assertNull($pc->getObligations()[0]->lastFulfilledAt());
        self::assertNull($pc->getObligations()[0]->nextDueAt());

        $this->expectException(AppException::class); // подписанный — заморожен
        $pc->signWriteOffAct((string) $writeOffId, $this->commission(), '40', new \DateTimeImmutable('2026-09-01'), 'scan-wo-2', $this->now, $this->calc);
    }

    public function test_write_off_without_signed_document_throws(): void
    {
        $pc = $this->pcWithGloves();
        $pc->formDraft(Uuid::v4(), $this->reqId, $this->now); // только черновик, не подписан

        $this->expectException(AppException::class); // списать можно лишь из действующей карточки
        $pc->writeOff(Uuid::v4(), $this->reqId, [$this->glovesKey()], $this->now);
    }

    private function signedGlovesCard(ProfileCompliance $pc, \DateTimeImmutable $at): void
    {
        $docId = Uuid::v4();
        $pc->formDraft($docId, $this->reqId, $at);
        $pc->signDraft((string) $docId, 'scan-1', [$this->glovesLine(10.0, $at->format('Y-m-d'))], $this->calc, $at);
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
