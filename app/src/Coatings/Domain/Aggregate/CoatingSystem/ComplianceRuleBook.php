<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\CoatingSystem\Iso12944\IsoDurability;

/**
 * Правила соответствия защитных лакокрасочных систем требованиям ISO 12944-5:2019
 * (ГОСТ 34667.5—2021), Приложение B, таблицы B.2 … B.5.
 *
 * Каждая непустая ячейка таблицы (не «—» и не «*») превращается в одно правило.
 * Ячейка «*» в стандарте означает «покрытие не требуется, взять систему для большей
 * коррозионной активности или большей долговечности» — правило не создаётся.
 * Ячейка «—» означает «неприменимо» — правило не создаётся.
 */
final class ComplianceRuleBook
{
    /** @return list<ComplianceRule> */
    public static function rules(): array
    {
        return array_merge(
            self::b2Rules(),
            self::b3Rules(),
            self::b4Rules(),
            self::b5Rules(),
        );
    }

    /**
     * Таблица B.2 — углеродистая сталь после абразивной струйной обработки (Sa 2½).
     * 12 столбцов: {l,m,h,vh} × {Zn(R)/ESI-EP-PUR, Прочие/EP-PUR-ESI, Прочие/AK-AY}.
     * Последующие слои: EP-PUR-AY для колонок Zn(R) и EP-PUR-ESI; AK-AY для колонок AK-AY.
     *
     * @return list<ComplianceRule>
     */
    private static function b2Rules(): array
    {
        $substrate      = Substrate::STEEL_CARBON;
        $primerZn       = [CoatingBase::ESI, CoatingBase::EP, CoatingBase::PUR];
        $primerOtherEpe = [CoatingBase::EP, CoatingBase::PUR, CoatingBase::ESI];
        $primerOtherAkAy = [CoatingBase::AK, CoatingBase::AY];
        $followupEpPurAy = [CoatingBase::EP, CoatingBase::PUR, CoatingBase::AY];
        $followupAkAy    = [CoatingBase::AK, CoatingBase::AY];

        $rules = [];

        // C2 — низкая (l) для всех трёх колонок отмечена «*»; средняя (m) Zn(R) и EP-PUR-ESI — «—».
        $rules[] = self::rule($substrate, 'C2', IsoDurability::MEDIUM,    PrimerType::OTHER,     1, 100, $primerOtherAkAy, $followupAkAy);
        $rules[] = self::rule($substrate, 'C2', IsoDurability::HIGH,      PrimerType::ZINC_RICH, 1, 60,  $primerZn,        $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C2', IsoDurability::HIGH,      PrimerType::OTHER,     1, 120, $primerOtherEpe,  $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C2', IsoDurability::HIGH,      PrimerType::OTHER,     1, 160, $primerOtherAkAy, $followupAkAy);
        $rules[] = self::rule($substrate, 'C2', IsoDurability::VERY_HIGH, PrimerType::ZINC_RICH, 2, 160, $primerZn,        $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C2', IsoDurability::VERY_HIGH, PrimerType::OTHER,     2, 180, $primerOtherEpe,  $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C2', IsoDurability::VERY_HIGH, PrimerType::OTHER,     2, 200, $primerOtherAkAy, $followupAkAy);

        // C3 — низкая (l) Zn(R) и EP-PUR-ESI — «—».
        $rules[] = self::rule($substrate, 'C3', IsoDurability::LOW,       PrimerType::OTHER,     1, 100, $primerOtherAkAy, $followupAkAy);
        $rules[] = self::rule($substrate, 'C3', IsoDurability::MEDIUM,    PrimerType::ZINC_RICH, 1, 60,  $primerZn,        $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C3', IsoDurability::MEDIUM,    PrimerType::OTHER,     1, 120, $primerOtherEpe,  $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C3', IsoDurability::MEDIUM,    PrimerType::OTHER,     1, 160, $primerOtherAkAy, $followupAkAy);
        $rules[] = self::rule($substrate, 'C3', IsoDurability::HIGH,      PrimerType::ZINC_RICH, 2, 160, $primerZn,        $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C3', IsoDurability::HIGH,      PrimerType::OTHER,     2, 180, $primerOtherEpe,  $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C3', IsoDurability::HIGH,      PrimerType::OTHER,     2, 200, $primerOtherAkAy, $followupAkAy);
        $rules[] = self::rule($substrate, 'C3', IsoDurability::VERY_HIGH, PrimerType::ZINC_RICH, 2, 200, $primerZn,        $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C3', IsoDurability::VERY_HIGH, PrimerType::OTHER,     2, 240, $primerOtherEpe,  $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C3', IsoDurability::VERY_HIGH, PrimerType::OTHER,     2, 260, $primerOtherAkAy, $followupAkAy);

        // C4 — vh/AK-AY отмечен «—».
        $rules[] = self::rule($substrate, 'C4', IsoDurability::LOW,       PrimerType::ZINC_RICH, 1, 60,  $primerZn,        $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::LOW,       PrimerType::OTHER,     1, 120, $primerOtherEpe,  $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::LOW,       PrimerType::OTHER,     1, 160, $primerOtherAkAy, $followupAkAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::MEDIUM,    PrimerType::ZINC_RICH, 2, 160, $primerZn,        $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::MEDIUM,    PrimerType::OTHER,     2, 180, $primerOtherEpe,  $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::MEDIUM,    PrimerType::OTHER,     2, 200, $primerOtherAkAy, $followupAkAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::HIGH,      PrimerType::ZINC_RICH, 2, 200, $primerZn,        $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::HIGH,      PrimerType::OTHER,     2, 240, $primerOtherEpe,  $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::HIGH,      PrimerType::OTHER,     2, 260, $primerOtherAkAy, $followupAkAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::VERY_HIGH, PrimerType::ZINC_RICH, 3, 260, $primerZn,        $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::VERY_HIGH, PrimerType::OTHER,     2, 300, $primerOtherEpe,  $followupEpPurAy);

        // C5 — все колонки AK-AY отмечены «—» (для всех долговечностей).
        $rules[] = self::rule($substrate, 'C5', IsoDurability::LOW,       PrimerType::ZINC_RICH, 2, 160, $primerZn,        $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C5', IsoDurability::LOW,       PrimerType::OTHER,     2, 180, $primerOtherEpe,  $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C5', IsoDurability::MEDIUM,    PrimerType::ZINC_RICH, 2, 200, $primerZn,        $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C5', IsoDurability::MEDIUM,    PrimerType::OTHER,     2, 240, $primerOtherEpe,  $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C5', IsoDurability::HIGH,      PrimerType::ZINC_RICH, 3, 260, $primerZn,        $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C5', IsoDurability::HIGH,      PrimerType::OTHER,     2, 300, $primerOtherEpe,  $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C5', IsoDurability::VERY_HIGH, PrimerType::ZINC_RICH, 3, 320, $primerZn,        $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C5', IsoDurability::VERY_HIGH, PrimerType::OTHER,     3, 360, $primerOtherEpe,  $followupEpPurAy);

        return $rules;
    }

    /**
     * Таблица B.3 — сталь горячего цинкования по ГОСТ 9.307.
     * Столбцы {l,m,h,vh} × {EP-PUR/EP-PUR-AY, AY/AY}. Все — PrimerType::OTHER
     * (Zn(R) на цинкованном металле не применяется, долговечность обеспечивается
     * адгезией и остаточным цинковым слоем).
     *
     * @return list<ComplianceRule>
     */
    private static function b3Rules(): array
    {
        $substrate      = Substrate::STEEL_GALVANIZED;
        $primerEpPur    = [CoatingBase::EP, CoatingBase::PUR];
        $primerAy       = [CoatingBase::AY];
        $followupEpPurAy = [CoatingBase::EP, CoatingBase::PUR, CoatingBase::AY];
        $followupAy     = [CoatingBase::AY];

        $rules = [];

        // C2 — l и m отмечены «*».
        $rules[] = self::rule($substrate, 'C2', IsoDurability::HIGH,      PrimerType::OTHER, 1, 80,  $primerEpPur, $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C2', IsoDurability::HIGH,      PrimerType::OTHER, 1, 80,  $primerAy,    $followupAy);
        $rules[] = self::rule($substrate, 'C2', IsoDurability::VERY_HIGH, PrimerType::OTHER, 1, 120, $primerEpPur, $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C2', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 160, $primerAy,    $followupAy);

        // C3 — l отмечена «*».
        $rules[] = self::rule($substrate, 'C3', IsoDurability::MEDIUM,    PrimerType::OTHER, 1, 80,  $primerEpPur, $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C3', IsoDurability::MEDIUM,    PrimerType::OTHER, 1, 80,  $primerAy,    $followupAy);
        $rules[] = self::rule($substrate, 'C3', IsoDurability::HIGH,      PrimerType::OTHER, 1, 120, $primerEpPur, $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C3', IsoDurability::HIGH,      PrimerType::OTHER, 2, 160, $primerAy,    $followupAy);
        $rules[] = self::rule($substrate, 'C3', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 160, $primerEpPur, $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C3', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 200, $primerAy,    $followupAy);

        // C4 — vh/AY отмечена «—».
        $rules[] = self::rule($substrate, 'C4', IsoDurability::LOW,       PrimerType::OTHER, 1, 80,  $primerEpPur, $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::LOW,       PrimerType::OTHER, 1, 80,  $primerAy,    $followupAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::MEDIUM,    PrimerType::OTHER, 1, 120, $primerEpPur, $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::MEDIUM,    PrimerType::OTHER, 2, 160, $primerAy,    $followupAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::HIGH,      PrimerType::OTHER, 2, 160, $primerEpPur, $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::HIGH,      PrimerType::OTHER, 2, 200, $primerAy,    $followupAy);
        $rules[] = self::rule($substrate, 'C4', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 200, $primerEpPur, $followupEpPurAy);

        // C5 — h/AY и vh/AY отмечены «—».
        $rules[] = self::rule($substrate, 'C5', IsoDurability::LOW,       PrimerType::OTHER, 1, 120, $primerEpPur, $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C5', IsoDurability::LOW,       PrimerType::OTHER, 2, 160, $primerAy,    $followupAy);
        $rules[] = self::rule($substrate, 'C5', IsoDurability::MEDIUM,    PrimerType::OTHER, 2, 160, $primerEpPur, $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C5', IsoDurability::MEDIUM,    PrimerType::OTHER, 2, 200, $primerAy,    $followupAy);
        $rules[] = self::rule($substrate, 'C5', IsoDurability::HIGH,      PrimerType::OTHER, 2, 200, $primerEpPur, $followupEpPurAy);
        $rules[] = self::rule($substrate, 'C5', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 240, $primerEpPur, $followupEpPurAy);

        return $rules;
    }

    /**
     * Таблица B.4 — стальные поверхности с термически напылённым металлом.
     * Только h и vh; только колонка EP-PUR последующих слоёв (грунтом служит
     * сам металлизированный слой, отдельного ЛКМ-грунта нет — потому primerType=OTHER,
     * а primerBinders совпадают с otherBinders (наносимая первой краска идёт как грунт)).
     *
     * @return list<ComplianceRule>
     */
    private static function b4Rules(): array
    {
        $substrate     = Substrate::STEEL_METALLIZED;
        $binders       = [CoatingBase::EP, CoatingBase::PUR];

        return [
            self::rule($substrate, 'C3', IsoDurability::HIGH,      PrimerType::OTHER, 1, 120, $binders, $binders),
            self::rule($substrate, 'C3', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 160, $binders, $binders),
            self::rule($substrate, 'C4', IsoDurability::HIGH,      PrimerType::OTHER, 2, 160, $binders, $binders),
            self::rule($substrate, 'C4', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 200, $binders, $binders),
            self::rule($substrate, 'C5', IsoDurability::HIGH,      PrimerType::OTHER, 2, 200, $binders, $binders),
            self::rule($substrate, 'C5', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 240, $binders, $binders),
        ];
    }

    /**
     * Таблица B.5 — углеродистая сталь для трёх категорий погружения Im1, Im2, Im3
     * (грунт и вода), долговечности h и vh, после абразивной струйной обработки.
     * 6 столбцов: {h,vh} × {Zn(R)/ESI-EP-PUR, Прочие/EP-PUR, без-грунтовки/EP-PUR}.
     * Последующие слои во всех колонках — EP-PUR. Для «без грунтовки» primer = followup
     * (система из одного толстого слоя того же материала).
     *
     * @return list<ComplianceRule>
     */
    private static function b5Rules(): array
    {
        $substrate  = Substrate::STEEL_CARBON;
        $primerZn   = [CoatingBase::ESI, CoatingBase::EP, CoatingBase::PUR];
        $primerEpPur = [CoatingBase::EP, CoatingBase::PUR];
        $followup   = [CoatingBase::EP, CoatingBase::PUR];

        $rules = [];
        foreach (['Im1', 'Im2', 'Im3'] as $category) {
            $rules[] = self::rule($substrate, $category, IsoDurability::HIGH,      PrimerType::ZINC_RICH, 2, 360, $primerZn,    $followup);
            $rules[] = self::rule($substrate, $category, IsoDurability::HIGH,      PrimerType::OTHER,     2, 380, $primerEpPur, $followup);
            $rules[] = self::rule($substrate, $category, IsoDurability::HIGH,      PrimerType::OTHER,     1, 400, $primerEpPur, $followup);
            $rules[] = self::rule($substrate, $category, IsoDurability::VERY_HIGH, PrimerType::ZINC_RICH, 2, 500, $primerZn,    $followup);
            $rules[] = self::rule($substrate, $category, IsoDurability::VERY_HIGH, PrimerType::OTHER,     2, 540, $primerEpPur, $followup);
            $rules[] = self::rule($substrate, $category, IsoDurability::VERY_HIGH, PrimerType::OTHER,     1, 600, $primerEpPur, $followup);
        }

        return $rules;
    }

    /**
     * @param list<CoatingBase> $primerBinders
     * @param list<CoatingBase> $otherBinders
     */
    private static function rule(
        Substrate $substrate,
        string $category,
        IsoDurability $durability,
        PrimerType $primerType,
        int $mnoc,
        int $ndft,
        array $primerBinders,
        array $otherBinders,
    ): ComplianceRule {
        return new ComplianceRule(
            standard: ComplianceStandard::ISO_12944,
            substrate: $substrate,
            category: $category,
            durability: $durability->value,
            primerType: $primerType,
            mnoc: $mnoc,
            ndft: $ndft,
            primerBinders: $primerBinders,
            otherBinders: $otherBinders,
        );
    }
}
