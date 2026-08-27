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

    public function test_can_be_applied_over_self_by_default(): void
    {
        $this->assertTrue(CoatingBase::EP->canBeAppliedOnTopOf(CoatingBase::EP));
        $this->assertTrue(CoatingBase::PUR->canBeAppliedOnTopOf(CoatingBase::PUR));
    }

    public function test_can_receive_mirrors_can_be_applied_on_top_of(): void
    {
        $this->assertTrue(CoatingBase::EP->canBecoveredBy(CoatingBase::EP));
        foreach (CoatingBase::cases() as $primer) {
            foreach (CoatingBase::cases() as $top) {
                $this->assertSame(
                    $top->canBeAppliedOnTopOf($primer),
                    $primer->canBecoveredBy($top),
                );
            }
        }
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
