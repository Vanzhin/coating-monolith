<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\Requirement;

use App\Compliance\Domain\Aggregate\Requirement\Requirement;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\JournalKind;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Compliance\Domain\ValueObject\Item\PeriodicItem;
use App\Compliance\Domain\ValueObject\Item\RequirementItemInterface;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RequirementTest extends TestCase
{
    private function gloves(): MaterialItem
    {
        return new MaterialItem('itm-gloves', 'Перчатки', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), 'п.5', new Quantity(10.0, Unit::Pair));
    }

    private function helmet(): MaterialItem
    {
        return new MaterialItem('itm-helmet', 'Каска', new Cadence(CadenceKind::Periodic, 2, PeriodUnit::Year), 'п.6', new Quantity(1.0, Unit::Piece));
    }

    private function briefing(): PeriodicItem
    {
        return new PeriodicItem('itm-briefing', 'Инструктаж', new Cadence(CadenceKind::Once), 'ГОСТ 12.0.004', new Quantity(1.0, Unit::Piece));
    }

    private function materialItem(string $id, string $label): MaterialItem
    {
        return MaterialItem::fromArray([
            'id' => $id,
            'label' => $label,
            'cadence' => ['kind' => 'once'],
            'basis' => 'п.1',
            'quantity' => ['amount' => 1.0, 'unit' => 'pcs'],
        ]);
    }

    /** «Выдача» теперь полиморфна (инвентарь + расходник в одной карточке) — помощник принимает любой вид позиции. */
    private function material(RequirementItemInterface ...$items): Requirement
    {
        return new Requirement(
            Uuid::v4(),
            'Личная карточка учёта выдачи СИЗ',
            ComplianceType::Material,
            new StringCollection('pos-1', 'pos-2'),
            ...$items,
        );
    }

    private function periodic(PeriodicItem ...$items): Requirement
    {
        return new Requirement(
            Uuid::v4(),
            'Журнал инструктажей',
            ComplianceType::Periodic,
            new StringCollection('pos-1', 'pos-2'),
            ...$items,
        );
    }

    public function test_builds_and_lazily_types_items(): void
    {
        $req = $this->material($this->gloves(), $this->helmet());

        self::assertSame(ComplianceType::Material, $req->getType());
        self::assertSame('Личная карточка учёта выдачи СИЗ', $req->getName());
        self::assertCount(2, $req->getItems());
        self::assertContainsOnlyInstancesOf(MaterialItem::class, $req->getItems());
    }

    public function test_get_items_round_trips_quantity(): void
    {
        $req = $this->material($this->gloves());

        $item = $req->getItems()[0];
        self::assertInstanceOf(MaterialItem::class, $item);
        self::assertSame(10.0, $item->quantity()->amount);
        self::assertSame(Unit::Pair, $item->quantity()->unit);
    }

    public function test_material_requirement_supports_both_item_kinds(): void
    {
        // «Выдача» — личная карточка СИЗ: держит и инвентарь (MaterialItem), и расходник (PeriodicItem) вместе.
        $req = $this->material();

        self::assertTrue($req->supports($this->gloves()));
        self::assertTrue($req->supports($this->briefing()));
    }

    public function test_periodic_requirement_supports_only_periodic_items(): void
    {
        $req = $this->periodic();

        self::assertTrue($req->supports($this->briefing()));
        self::assertFalse($req->supports($this->gloves()));
    }

    public function test_material_requirement_accepts_mixed_items(): void
    {
        $req = $this->material($this->gloves(), $this->briefing());

        self::assertCount(2, $req->getItems());
        self::assertInstanceOf(MaterialItem::class, $req->getItems()[0]);
        self::assertInstanceOf(PeriodicItem::class, $req->getItems()[1]);
    }

    public function test_periodic_requirement_rejects_material_item(): void
    {
        $this->expectException(AppException::class);
        new Requirement(
            Uuid::v4(),
            'Журнал инструктажей',
            ComplianceType::Periodic,
            new StringCollection('pos-1'),
            $this->gloves(),
        );
    }

    public function test_rejects_duplicate_label(): void
    {
        $this->expectException(AppException::class);
        $this->material($this->gloves(), $this->gloves());
    }

    public function test_merge_items_accepts_mixed_items_for_material_requirement(): void
    {
        $req = $this->material($this->gloves());

        $req->mergeItems($this->gloves(), $this->briefing());

        self::assertCount(2, $req->getItems());
    }

    public function test_merge_items_revalidates_type_for_periodic_requirement(): void
    {
        $req = $this->periodic($this->briefing());

        $this->expectException(AppException::class);
        $req->mergeItems($this->gloves());
    }

    public function test_blank_name_rejected(): void
    {
        $this->expectException(AppException::class);
        new Requirement(Uuid::v4(), '  ', ComplianceType::Material, new StringCollection('pos-1'));
    }

    public function test_covers_position(): void
    {
        $req = $this->material();

        self::assertTrue($req->coversPosition('pos-1'));
        self::assertFalse($req->coversPosition('pos-999'));
    }

    public function test_template_file_id_defaults_null_and_sets(): void
    {
        $req = $this->material($this->gloves());

        self::assertNull($req->getTemplateFileId(), 'без шаблона — null (печатается дефолтная карточка)');

        $req->setTemplateFileId('file-uuid-1');
        self::assertSame('file-uuid-1', $req->getTemplateFileId());

        $req->setTemplateFileId('');
        self::assertNull($req->getTemplateFileId(), 'пустая строка → null');
    }

    public function test_periodic_requirement_keeps_journal_kind(): void
    {
        // Журнал — атрибут периодического требования (инструктаж/процедура), см. Requirement::setJournalKind.
        $req = $this->periodic($this->briefing());

        $req->setJournalKind(JournalKind::FireSafety);
        self::assertSame(JournalKind::FireSafety, $req->getJournalKind());
    }

    public function test_material_requirement_never_carries_journal_kind(): void
    {
        $req = $this->material($this->gloves());

        // Форма могла прислать вид журнала из скрытого селекта при переключении типа — материальное
        // (выдача СИЗ) журнала не носит, иначе подпись акта упрётся в проверку полей инструктажа.
        $req->setJournalKind(JournalKind::FireSafety);
        self::assertNull($req->getJournalKind());
    }

    public function test_merge_soft_removes_absent_item_and_active_excludes_it(): void
    {
        $gloves = $this->materialItem('g1', 'Перчатки');
        $boots = $this->materialItem('b1', 'Ботинки');
        $req = new Requirement(Uuid::v7(), 'Карточка', ComplianceType::Material, new StringCollection('pos1'), $gloves, $boots);

        // пере-сохранение формы прислало только перчатки → ботинки должны стать removed, не исчезнуть
        $req->mergeItems($this->materialItem('g1', 'Перчатки'));

        self::assertCount(2, $req->getItems());                       // надгробие сохранено
        self::assertCount(1, $req->activeItems());                    // активна одна
        self::assertSame('Перчатки', $req->activeItems()[0]->label());
        $bootsNow = array_values(array_filter($req->getItems(), static fn ($i) => 'b1' === $i->id()))[0];
        self::assertTrue($bootsNow->isRemoved());
    }

    public function test_merge_allows_new_active_item_with_same_label_as_removed_tombstone(): void
    {
        $socks = $this->materialItem('s1', 'Носки');
        $req = new Requirement(Uuid::v7(), 'Карточка', ComplianceType::Material, new StringCollection('pos1'), $socks);
        $req->mergeItems(); // убрали носки → надгробие
        $req->mergeItems($this->materialItem('s2', 'Носки')); // новая активная с тем же названием — дедуп не падает
        self::assertCount(1, $req->activeItems());
        self::assertSame('Носки', $req->activeItems()[0]->label());
    }
}
