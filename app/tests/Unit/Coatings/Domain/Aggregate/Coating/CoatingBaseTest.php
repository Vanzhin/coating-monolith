<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use PHPUnit\Framework\TestCase;

class CoatingBaseTest extends TestCase
{
    public function testIsoIsTheValue(): void
    {
        $this->assertSame('EP', CoatingBase::EP->iso());
        $this->assertSame('FEVE', CoatingBase::FEVE->iso());
    }

    public function testGostList(): void
    {
        $this->assertSame(['ЭП'], CoatingBase::EP->gost());
        $this->assertSame(['ПФ', 'ГФ', 'АУ'], CoatingBase::AK->gost());
        $this->assertSame([], CoatingBase::PAS->gost());
    }

    public function testFromGostReturnsCase(): void
    {
        $this->assertSame(CoatingBase::EP, CoatingBase::fromGost('ЭП'));
        $this->assertSame(CoatingBase::AK, CoatingBase::fromGost('ПФ'));
        $this->assertSame(CoatingBase::AK, CoatingBase::fromGost('  пф  '));
        $this->assertSame(CoatingBase::PUR, CoatingBase::fromGost('ур'));
    }

    public function testFromGostReturnsNullForUnknown(): void
    {
        $this->assertNull(CoatingBase::fromGost('XX'));
        $this->assertNull(CoatingBase::fromGost(''));
        $this->assertNull(CoatingBase::fromGost('   '));
    }

    public function testCanBeAppliedOverSelfByDefault(): void
    {
        $this->assertTrue(CoatingBase::EP->canBeAppliedOnTopOf(CoatingBase::EP));
        $this->assertTrue(CoatingBase::PUR->canBeAppliedOnTopOf(CoatingBase::PUR));
    }

    public function testCanReceiveMirrorsCanBeAppliedOnTopOf(): void
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
}
