<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Coatings\Domain\Compliance\Iso12944\IsoCorrosivityCategory;
use App\Coatings\Domain\Compliance\Iso12944\IsoDurability;
use App\Coatings\Domain\Compliance\Iso12944\PrimerType;
use PHPUnit\Framework\TestCase;

final class EnumTitlesTest extends TestCase
{
    public function test_substrate_titles(): void
    {
        self::assertSame('Углеродистая сталь', Substrate::STEEL_CARBON->title());
        self::assertSame('Оцинкованная сталь', Substrate::STEEL_GALVANIZED->title());
        self::assertSame('Бетон', Substrate::CONCRETE->title());
    }

    public function test_compliance_standard_titles(): void
    {
        self::assertSame('ГОСТ 34667.5 (ISO 12944-5)', ComplianceStandard::ISO_12944->title());
        self::assertStringContainsString('ГОСТ 34667.5', ComplianceStandard::ISO_12944->description());
    }

    public function test_primer_type_titles(): void
    {
        self::assertSame('Zn(R)', PrimerType::ZINC_RICH->title());
        self::assertSame('Прочие', PrimerType::OTHER->title());
    }

    public function test_iso_corrosivity_titles(): void
    {
        self::assertSame('C1', IsoCorrosivityCategory::C1->title());
        self::assertSame('Очень низкая', IsoCorrosivityCategory::C1->description());
        self::assertSame('C3', IsoCorrosivityCategory::C3->title());
        self::assertSame('Средняя', IsoCorrosivityCategory::C3->description());
        self::assertSame('Im1', IsoCorrosivityCategory::IM1->title());
    }

    public function test_iso_durability_titles(): void
    {
        self::assertSame('L', IsoDurability::LOW->title());
        self::assertStringContainsString('менее 7', IsoDurability::LOW->description());
        self::assertSame('VH', IsoDurability::VERY_HIGH->title());
        self::assertStringContainsString('более 25', IsoDurability::VERY_HIGH->description());
    }
}
