<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use PHPUnit\Framework\TestCase;

class CoatingBaseTest extends TestCase
{
    public function test_iso_is_the_value(): void
    {
        $this->assertSame('EP', CoatingBase::EP->iso());
        $this->assertSame('FEVE', CoatingBase::FEVE->iso());
    }

    public function test_gost_list(): void
    {
        $this->assertSame(['ЭП'], CoatingBase::EP->gost());
        $this->assertSame(['ПФ', 'ГФ', 'АУ'], CoatingBase::AK->gost());
        $this->assertSame([], CoatingBase::PAS->gost());
    }

    public function test_from_gost_returns_case(): void
    {
        $this->assertSame(CoatingBase::EP, CoatingBase::fromGost('ЭП'));
        $this->assertSame(CoatingBase::AK, CoatingBase::fromGost('ПФ'));
        $this->assertSame(CoatingBase::AK, CoatingBase::fromGost('  пф  '));
        $this->assertSame(CoatingBase::PUR, CoatingBase::fromGost('ур'));
    }

    public function test_from_gost_returns_null_for_unknown(): void
    {
        $this->assertNull(CoatingBase::fromGost('XX'));
        $this->assertNull(CoatingBase::fromGost(''));
        $this->assertNull(CoatingBase::fromGost('   '));
    }

    public function test_allowed_primers_matrix_iso12944(): void
    {
        // Матрица ISO 12944-5 (таблица F.1 + прим. к C.1–C.5) — только данные.
        // Решение о совместимости принимает Coating (см. CoatingTest), не enum.
        $this->assertContains(CoatingBase::AY, CoatingBase::EP->allowedPrimers());
        $this->assertContains(CoatingBase::AY, CoatingBase::PUR->allowedPrimers());
        foreach ([CoatingBase::EP, CoatingBase::PUR, CoatingBase::ESI] as $primer) {
            $this->assertContains($primer, CoatingBase::FEVE->allowedPrimers());
            $this->assertContains($primer, CoatingBase::PAS->allowedPrimers());
        }
        $this->assertContains(CoatingBase::EP, CoatingBase::EP->allowedPrimers());
        $this->assertSame([CoatingBase::ESI], CoatingBase::ESI->allowedPrimers());
    }

    public function test_default_dry_heat_max_operating_temp_for_low_heat_bases(): void
    {
        $this->assertSame(50, CoatingBase::AY->defaultDryHeatMaxOperatingTemp());
        $this->assertSame(50, CoatingBase::FEVE->defaultDryHeatMaxOperatingTemp());
        $this->assertSame(50, CoatingBase::PAS->defaultDryHeatMaxOperatingTemp());
    }

    public function test_default_dry_heat_max_operating_temp_for_other_bases(): void
    {
        $this->assertSame(120, CoatingBase::AK->defaultDryHeatMaxOperatingTemp());
        $this->assertSame(120, CoatingBase::ESI->defaultDryHeatMaxOperatingTemp());
        $this->assertSame(120, CoatingBase::EP->defaultDryHeatMaxOperatingTemp());
        $this->assertSame(120, CoatingBase::PUR->defaultDryHeatMaxOperatingTemp());
        $this->assertSame(120, CoatingBase::PS->defaultDryHeatMaxOperatingTemp());
    }
}
