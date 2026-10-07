<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\Requirement;

use App\Compliance\Domain\Aggregate\Requirement\Requirement;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Compliance\Domain\ValueObject\Item\NonMaterialItem;
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
        return new MaterialItem('Перчатки', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), 'п.5', new Quantity(10.0, Unit::Pair));
    }

    private function helmet(): MaterialItem
    {
        return new MaterialItem('Каска', new Cadence(CadenceKind::Periodic, 2, PeriodUnit::Year), 'п.6', new Quantity(1.0, Unit::Piece));
    }

    private function briefing(): NonMaterialItem
    {
        return new NonMaterialItem('Инструктаж', new Cadence(CadenceKind::Once), 'ГОСТ 12.0.004');
    }

    private function material(MaterialItem ...$items): Requirement
    {
        return new Requirement(
            Uuid::v4(),
            'Личная карточка учёта выдачи СИЗ',
            ComplianceType::Material,
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

    public function test_supports_matches_own_type(): void
    {
        $req = $this->material();

        self::assertTrue($req->supports($this->gloves()));
        self::assertFalse($req->supports($this->briefing()));
    }

    public function test_rejects_foreign_type_item(): void
    {
        $this->expectException(AppException::class);
        new Requirement(
            Uuid::v4(),
            'Смешанное',
            ComplianceType::Material,
            new StringCollection('pos-1'),
            $this->gloves(),
            $this->briefing(),
        );
    }

    public function test_rejects_duplicate_label(): void
    {
        $this->expectException(AppException::class);
        $this->material($this->gloves(), $this->gloves());
    }

    public function test_replace_items_revalidates_type(): void
    {
        $req = $this->material($this->gloves());

        $this->expectException(AppException::class);
        $req->replaceItems($this->briefing());
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
}
