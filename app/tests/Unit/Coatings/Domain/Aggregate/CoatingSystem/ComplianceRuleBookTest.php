<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceRule;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceRuleBook;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\PrimerType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use PHPUnit\Framework\TestCase;

final class ComplianceRuleBookTest extends TestCase
{
    public function test_rule_book_is_not_empty(): void
    {
        self::assertNotEmpty(ComplianceRuleBook::rules());
    }

    /**
     * Таблица B.2 ГОСТ 34667.5-2021, сталь+Sa 2½, C3/High/Zn(R):
     * primer=ESI EP PUR, followup=EP PUR AY, MNOC=2, NDFT=160.
     */
    public function test_b2_c3_high_zinc_rich_present(): void
    {
        $rule = $this->findUniqueRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
        );
        self::assertSame(2, $rule->mnoc);
        self::assertSame(160, $rule->ndft);
        self::assertContains(CoatingBase::EP, $rule->primerBinders);
        self::assertContains(CoatingBase::PUR, $rule->primerBinders);
        self::assertContains(CoatingBase::ESI, $rule->primerBinders);
        self::assertContains(CoatingBase::EP, $rule->otherBinders);
        self::assertContains(CoatingBase::PUR, $rule->otherBinders);
        self::assertContains(CoatingBase::AY, $rule->otherBinders);
    }

    /**
     * Таблица B.2, сталь+Sa 2½, C5/VeryHigh/Прочие с primer EP PUR ESI:
     * MNOC=3, NDFT=360.
     */
    public function test_b2_c5_very_high_other_ep_pur_esi_present(): void
    {
        $rules = array_values(array_filter(
            ComplianceRuleBook::rules(),
            static fn (ComplianceRule $r) =>
                $r->standard === ComplianceStandard::ISO_12944
                && $r->substrate === Substrate::STEEL_CARBON
                && $r->category === 'C5'
                && $r->durability === 'VERY_HIGH'
                && $r->primerType === PrimerType::OTHER
                && in_array(CoatingBase::ESI, $r->primerBinders, true),
        ));
        self::assertCount(1, $rules);
        $rule = $rules[0];
        self::assertSame(3, $rule->mnoc);
        self::assertSame(360, $rule->ndft);
    }

    /**
     * Таблица B.3 ГОСТ 34667.5-2021, горячее цинкование, C3/Medium
     * (столбец с primer EP+PUR, followup EP+PUR+AY): MNOC=1, NDFT=80.
     */
    public function test_b3_c3_medium_ep_pur_present(): void
    {
        $rules = array_values(array_filter(
            ComplianceRuleBook::rules(),
            static fn (ComplianceRule $r) =>
                $r->standard === ComplianceStandard::ISO_12944
                && $r->substrate === Substrate::STEEL_GALVANIZED
                && $r->category === 'C3'
                && $r->durability === 'MEDIUM'
                && in_array(CoatingBase::EP, $r->primerBinders, true)
                && in_array(CoatingBase::PUR, $r->primerBinders, true),
        ));
        self::assertCount(1, $rules);
        $rule = $rules[0];
        self::assertSame(1, $rule->mnoc);
        self::assertSame(80, $rule->ndft);
    }

    /**
     * Таблица B.4 ГОСТ 34667.5-2021, термически напылённый металл,
     * C4/High: MNOC=2, NDFT=160, followup=EP PUR.
     */
    public function test_b4_c4_high_present(): void
    {
        $rule = $this->findUniqueRule(
            substrate: Substrate::STEEL_METALLIZED,
            category: 'C4',
            durability: 'HIGH',
            primerType: PrimerType::OTHER,
        );
        self::assertSame(2, $rule->mnoc);
        self::assertSame(160, $rule->ndft);
        self::assertContains(CoatingBase::EP, $rule->otherBinders);
        self::assertContains(CoatingBase::PUR, $rule->otherBinders);
    }

    /**
     * Таблица B.5 ГОСТ 34667.5-2021, углеродистая сталь, погружение
     * (Im2), очень высокая долговечность, Zn(R): MNOC=2, NDFT=500.
     */
    public function test_b5_im2_very_high_zinc_rich_present(): void
    {
        $rule = $this->findUniqueRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'Im2',
            durability: 'VERY_HIGH',
            primerType: PrimerType::ZINC_RICH,
        );
        self::assertSame(2, $rule->mnoc);
        self::assertSame(500, $rule->ndft);
        self::assertContains(CoatingBase::ESI, $rule->primerBinders);
        self::assertContains(CoatingBase::EP, $rule->primerBinders);
        self::assertContains(CoatingBase::PUR, $rule->primerBinders);
    }

    private function findUniqueRule(
        Substrate $substrate,
        string $category,
        string $durability,
        PrimerType $primerType,
    ): ComplianceRule {
        $rules = array_values(array_filter(
            ComplianceRuleBook::rules(),
            static fn (ComplianceRule $r) =>
                $r->standard === ComplianceStandard::ISO_12944
                && $r->substrate === $substrate
                && $r->category === $category
                && $r->durability === $durability
                && $r->primerType === $primerType,
        ));
        self::assertCount(
            1,
            $rules,
            sprintf('expected exactly one rule for %s/%s/%s/%s', $substrate->value, $category, $durability, $primerType->value),
        );

        return $rules[0];
    }
}
