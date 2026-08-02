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
            static fn (ComplianceRule $r) => ComplianceStandard::ISO_12944 === $r->standard
                && Substrate::STEEL_CARBON === $r->substrate
                && 'C5' === $r->category
                && 'VERY_HIGH' === $r->durability
                && PrimerType::OTHER === $r->primerType
                && in_array(CoatingBase::ESI, $r->primerBinders, true),
        ));
        self::assertCount(1, $rules);
        $rule = $rules[0];
        self::assertSame(3, $rule->mnoc);
        self::assertSame(360, $rule->ndft);
    }

    /**
     * Таблица D.1 ГОСТ 34667.5-2021, оцинкованная сталь, C3/High
     * (строка G3.02: primer EP/PUR, followup EP/PUR/AY): MNOC=1, NDFT=120.
     */
    public function test_d1_c3_high_ep_pur_present(): void
    {
        $rules = array_values(array_filter(
            ComplianceRuleBook::rules(),
            static fn (ComplianceRule $r) => ComplianceStandard::ISO_12944 === $r->standard
                && Substrate::STEEL_GALVANIZED === $r->substrate
                && 'C3' === $r->category
                && 'HIGH' === $r->durability
                && [CoatingBase::EP, CoatingBase::PUR] === $r->primerBinders,
        ));
        self::assertCount(1, $rules);
        $rule = $rules[0];
        self::assertSame(1, $rule->mnoc);
        self::assertSame(120, $rule->ndft);
        self::assertSame(
            [CoatingBase::EP, CoatingBase::PUR, CoatingBase::AY],
            $rule->otherBinders,
        );
    }

    /**
     * Таблица D.1, оцинкованная сталь: G4.01 — только грунт EP/PUR/AY,
     * без последующих слоёв. Категория C4, LOW, MNOC=1, NDFT=80.
     */
    public function test_d1_g4_01_primer_only_row_present(): void
    {
        $rules = array_values(array_filter(
            ComplianceRuleBook::rules(),
            static fn (ComplianceRule $r) => ComplianceStandard::ISO_12944 === $r->standard
                && Substrate::STEEL_GALVANIZED === $r->substrate
                && 'C4' === $r->category
                && 'LOW' === $r->durability
                && [CoatingBase::EP, CoatingBase::PUR, CoatingBase::AY] === $r->primerBinders,
        ));
        self::assertCount(1, $rules);
        $rule = $rules[0];
        self::assertSame(1, $rule->mnoc);
        self::assertSame(80, $rule->ndft);
        self::assertSame([], $rule->otherBinders);
    }

    /**
     * Таблица D.1: C5/VERY_HIGH (G5.05) — MNOC=2, NDFT=240,
     * primer EP/PUR, followup EP/PUR/AY.
     */
    public function test_d1_c5_very_high_present(): void
    {
        $rules = array_values(array_filter(
            ComplianceRuleBook::rules(),
            static fn (ComplianceRule $r) => ComplianceStandard::ISO_12944 === $r->standard
                && Substrate::STEEL_GALVANIZED === $r->substrate
                && 'C5' === $r->category
                && 'VERY_HIGH' === $r->durability,
        ));
        self::assertCount(1, $rules);
        $rule = $rules[0];
        self::assertSame(2, $rule->mnoc);
        self::assertSame(240, $rule->ndft);
    }

    /**
     * Таблица D.1 не содержит правил Zn(R) для оцинкованной стали
     * (Zn-rich праймер на цинке не применяется).
     */
    public function test_d1_has_no_zinc_rich_rules(): void
    {
        $rules = array_values(array_filter(
            ComplianceRuleBook::rules(),
            static fn (ComplianceRule $r) => Substrate::STEEL_GALVANIZED === $r->substrate
                && PrimerType::ZINC_RICH === $r->primerType,
        ));
        self::assertSame([], $rules);
    }

    /**
     * Таблица E.1 ГОСТ 34667.5-2021, термически напылённый металл,
     * C4/High (TSM 4.01): MNOC=2, NDFT=160, followup=EP PUR.
     */
    public function test_e1_c4_high_present(): void
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
     * Таблица E.1 не содержит правил для C3 — только C4 и C5.
     */
    public function test_e1_has_no_c3_rules(): void
    {
        $rules = array_values(array_filter(
            ComplianceRuleBook::rules(),
            static fn (ComplianceRule $r) => Substrate::STEEL_METALLIZED === $r->substrate
                && 'C3' === $r->category,
        ));
        self::assertSame([], $rules);
    }

    /**
     * Таблица C.5 ГОСТ 34667.5-2021, углеродистая сталь, погружение
     * (Im2), очень высокая долговечность, Zn(R) (I.02): MNOC=2, NDFT=500.
     */
    public function test_c5_im2_very_high_zinc_rich_present(): void
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

    /**
     * Таблица C.1: C2.01 — AKAY primer только (без последующих слоёв,
     * mnoc=1, ndft=80), максимум LOW.
     */
    public function test_c1_c2_01_low_akay_present(): void
    {
        $rule = $this->findUniqueRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C2',
            durability: 'LOW',
            primerType: PrimerType::OTHER,
        );
        self::assertSame(1, $rule->mnoc);
        self::assertSame(80, $rule->ndft);
        self::assertSame([CoatingBase::AK, CoatingBase::AY], $rule->primerBinders);
        self::assertSame([CoatingBase::AK, CoatingBase::AY], $rule->otherBinders);
    }

    /**
     * Таблица C.1: C2.07 — только Zn(R) primer без последующих слоёв
     * (mnoc=1, ndft=60), максимум HIGH.
     */
    public function test_c1_c2_07_primer_only_zinc_rich(): void
    {
        $rule = $this->findUniqueRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C2',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
        );
        self::assertSame(1, $rule->mnoc);
        self::assertSame(60, $rule->ndft);
        self::assertSame([], $rule->otherBinders);
    }

    /**
     * Таблица C.2: C3.08 — только Zn(R) primer без последующих слоёв
     * (mnoc=1, ndft=60), максимум MEDIUM.
     */
    public function test_c2_c3_08_primer_only_zinc_rich(): void
    {
        $rule = $this->findUniqueRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'MEDIUM',
            primerType: PrimerType::ZINC_RICH,
        );
        self::assertSame(1, $rule->mnoc);
        self::assertSame(60, $rule->ndft);
        self::assertSame([], $rule->otherBinders);
    }

    /**
     * Таблица C.3: C4.08 — только Zn(R) primer без последующих слоёв
     * (mnoc=1, ndft=60), максимум LOW.
     */
    public function test_c3_c4_08_primer_only_zinc_rich(): void
    {
        $rule = $this->findUniqueRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C4',
            durability: 'LOW',
            primerType: PrimerType::ZINC_RICH,
        );
        self::assertSame(1, $rule->mnoc);
        self::assertSame(60, $rule->ndft);
        self::assertSame([], $rule->otherBinders);
    }

    /**
     * Таблица C.5: I.03 — primer EP/PUR/ESI (не только EP/PUR),
     * followup EP/PUR, mnoc=2, ndft=380. Максимум HIGH для Im1..Im3.
     */
    public function test_c5_i03_primer_includes_esi(): void
    {
        $rules = array_values(array_filter(
            ComplianceRuleBook::rules(),
            static fn (ComplianceRule $r) => Substrate::STEEL_CARBON === $r->substrate
                && 'Im1' === $r->category
                && 'HIGH' === $r->durability
                && PrimerType::OTHER === $r->primerType
                && 380 === $r->ndft,
        ));
        self::assertCount(1, $rules);
        self::assertContains(CoatingBase::ESI, $rules[0]->primerBinders);
        self::assertContains(CoatingBase::EP, $rules[0]->primerBinders);
        self::assertContains(CoatingBase::PUR, $rules[0]->primerBinders);
    }

    /**
     * Таблица C.5: I.04 — primer EP/PUR/ESI (не только EP/PUR),
     * followup EP/PUR, mnoc=2, ndft=540. Максимум VERY_HIGH для Im1..Im3.
     */
    public function test_c5_i04_primer_includes_esi(): void
    {
        $rules = array_values(array_filter(
            ComplianceRuleBook::rules(),
            static fn (ComplianceRule $r) => Substrate::STEEL_CARBON === $r->substrate
                && 'Im2' === $r->category
                && 'VERY_HIGH' === $r->durability
                && PrimerType::OTHER === $r->primerType
                && 540 === $r->ndft,
        ));
        self::assertCount(1, $rules);
        self::assertContains(CoatingBase::ESI, $rules[0]->primerBinders);
        self::assertContains(CoatingBase::EP, $rules[0]->primerBinders);
        self::assertContains(CoatingBase::PUR, $rules[0]->primerBinders);
    }

    private function findUniqueRule(
        Substrate $substrate,
        string $category,
        string $durability,
        PrimerType $primerType,
    ): ComplianceRule {
        $rules = array_values(array_filter(
            ComplianceRuleBook::rules(),
            static fn (ComplianceRule $r) => ComplianceStandard::ISO_12944 === $r->standard
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
