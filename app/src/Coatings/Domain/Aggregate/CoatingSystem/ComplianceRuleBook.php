<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\CoatingSystem\Iso12944\IsoDurability;

/**
 * Правила соответствия защитных лакокрасочных систем требованиям ISO 12944-5:2019
 * (ГОСТ 34667.5—2021):
 *   - Приложение C, таблицы C.1..C.4 — углеродистая сталь Sa 2½, атмосферные C2..C5.
 *   - Приложение C, таблица C.5 — углеродистая сталь, погружение Im1..Im3.
 *   - Приложение D, таблица D.1 — оцинкованная сталь, C2..C5.
 *   - Приложение E, таблица E.1 — стальные поверхности с термически напылённым металлом, C4..C5.
 *
 * Каждая непустая ячейка таблицы (не «—» и не «*») превращается в одно правило.
 * Ячейка «*» в стандарте означает «покрытие не требуется, взять систему для большей
 * коррозионной активности или большей долговечности» — правило не создаётся.
 * Ячейка «—» означает «неприменимо» — правило не создаётся.
 *
 * Для строк с несколькими «+»-долговечностями создаётся только правило с максимальной
 * долговечностью: меньшие покрываются автоматически, если ComplianceEvaluator свернёт
 * результат до сильнейших пар (ComplianceMatches::strongestOnly).
 */
final class ComplianceRuleBook
{
    /** @return list<ComplianceRule> */
    public static function rules(): array
    {
        return array_merge(
            self::b2Rules(),
            self::d1Rules(),
            self::e1Rules(),
            self::b5Rules(),
        );
    }

    /**
     * Таблицы C.1..C.4 ГОСТ 34667.5-2021 — углеродистая сталь после абразивной струйной
     * обработки (Sa 2½) для атмосферных категорий C2..C5. Всего 37 систем C2.01..C5.08:
     *   - C.1 (C2): 8 систем C2.01..C2.08
     *   - C.2 (C3): 10 систем C3.01..C3.10
     *   - C.3 (C4): 11 систем C4.01..C4.11
     *   - C.4 (C5): 8 систем C5.01..C5.08
     * Для каждой строки добавлено правило с максимальной отмеченной «+» долговечностью;
     * меньшие покрываются автоматически при свёртке до сильнейших пар. Для строк «только
     * Zn(R) primer» (C2.07, C3.08, C4.08) followup = [] — правило подходит лишь для систем
     * без последующих слоёв. Пропущены строки, отмеченные «*» в стандарте.
     *
     * @return list<ComplianceRule>
     */
    private static function b2Rules(): array
    {
        $substrate = Substrate::STEEL_CARBON;
        $primerZn = [CoatingBase::ESI, CoatingBase::EP, CoatingBase::PUR];
        $primerOtherEpe = [CoatingBase::EP, CoatingBase::PUR, CoatingBase::ESI];
        $primerOtherAkAy = [CoatingBase::AK, CoatingBase::AY];
        $followupEpPurAy = [CoatingBase::EP, CoatingBase::PUR, CoatingBase::AY];
        $followupAkAy = [CoatingBase::AK, CoatingBase::AY];

        $rules = [];

        // Таблица C.1 — категория C2.
        // C2.01 — AKAY primer + AKAY, mnoc=1-2, ndft=80, max=L.
        $rules[] = self::rule($substrate, 'C2', IsoDurability::LOW, PrimerType::OTHER, 1, 80, $primerOtherAkAy, $followupAkAy);
        // C2.02 — AKAY primer + AKAY, mnoc=1-2, ndft=100, max=M.
        $rules[] = self::rule($substrate, 'C2', IsoDurability::MEDIUM, PrimerType::OTHER, 1, 100, $primerOtherAkAy, $followupAkAy);
        // C2.03 — AKAY primer + AKAY, mnoc=1-2, ndft=160, max=H.
        $rules[] = self::rule($substrate, 'C2', IsoDurability::HIGH, PrimerType::OTHER, 1, 160, $primerOtherAkAy, $followupAkAy);
        // C2.04 — AKAY primer + AKAY, mnoc=2-3, ndft=200, max=VH.
        $rules[] = self::rule($substrate, 'C2', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 200, $primerOtherAkAy, $followupAkAy);
        // C2.05 — EP/PUR/ESI primer + EP/PUR/AY, mnoc=1-2, ndft=120, max=H.
        $rules[] = self::rule($substrate, 'C2', IsoDurability::HIGH, PrimerType::OTHER, 1, 120, $primerOtherEpe, $followupEpPurAy);
        // C2.06 — EP/PUR/ESI primer + EP/PUR/AY, mnoc=2, ndft=180, max=VH.
        $rules[] = self::rule($substrate, 'C2', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 180, $primerOtherEpe, $followupEpPurAy);
        // C2.07 — только Zn(R) primer EP/PUR/ESI без последующих, mnoc=1, ndft=60, max=H.
        $rules[] = self::rule($substrate, 'C2', IsoDurability::HIGH, PrimerType::ZINC_RICH, 1, 60, $primerZn, []);
        // C2.08 — Zn(R) primer + EP/PUR/AY, mnoc=2, ndft=160, max=VH.
        $rules[] = self::rule($substrate, 'C2', IsoDurability::VERY_HIGH, PrimerType::ZINC_RICH, 2, 160, $primerZn, $followupEpPurAy);

        // Таблица C.2 — категория C3.
        // C3.01 — AKAY primer + AKAY, mnoc=1-2, ndft=100, max=L.
        $rules[] = self::rule($substrate, 'C3', IsoDurability::LOW, PrimerType::OTHER, 1, 100, $primerOtherAkAy, $followupAkAy);
        // C3.02 — AKAY primer + AKAY, mnoc=1-2, ndft=160, max=M.
        $rules[] = self::rule($substrate, 'C3', IsoDurability::MEDIUM, PrimerType::OTHER, 1, 160, $primerOtherAkAy, $followupAkAy);
        // C3.03 — AKAY primer + AKAY, mnoc=2-3, ndft=200, max=H.
        $rules[] = self::rule($substrate, 'C3', IsoDurability::HIGH, PrimerType::OTHER, 2, 200, $primerOtherAkAy, $followupAkAy);
        // C3.04 — AKAY primer + AKAY, mnoc=2-4, ndft=260, max=VH.
        $rules[] = self::rule($substrate, 'C3', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 260, $primerOtherAkAy, $followupAkAy);
        // C3.05 — EP/PUR/ESI primer + EP/PUR/AY, mnoc=1-2, ndft=120, max=M.
        $rules[] = self::rule($substrate, 'C3', IsoDurability::MEDIUM, PrimerType::OTHER, 1, 120, $primerOtherEpe, $followupEpPurAy);
        // C3.06 — EP/PUR/ESI primer + EP/PUR/AY, mnoc=2, ndft=180, max=H.
        $rules[] = self::rule($substrate, 'C3', IsoDurability::HIGH, PrimerType::OTHER, 2, 180, $primerOtherEpe, $followupEpPurAy);
        // C3.07 — EP/PUR/ESI primer + EP/PUR/AY, mnoc=2-3, ndft=240, max=VH.
        $rules[] = self::rule($substrate, 'C3', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 240, $primerOtherEpe, $followupEpPurAy);
        // C3.08 — только Zn(R) primer EP/PUR/ESI без последующих, mnoc=1, ndft=60, max=M.
        $rules[] = self::rule($substrate, 'C3', IsoDurability::MEDIUM, PrimerType::ZINC_RICH, 1, 60, $primerZn, []);
        // C3.09 — Zn(R) primer + EP/PUR/AY, mnoc=2, ndft=160, max=H.
        $rules[] = self::rule($substrate, 'C3', IsoDurability::HIGH, PrimerType::ZINC_RICH, 2, 160, $primerZn, $followupEpPurAy);
        // C3.10 — Zn(R) primer + EP/PUR/AY, mnoc=2-3, ndft=200, max=VH.
        $rules[] = self::rule($substrate, 'C3', IsoDurability::VERY_HIGH, PrimerType::ZINC_RICH, 2, 200, $primerZn, $followupEpPurAy);

        // Таблица C.3 — категория C4.
        // C4.01 — AKAY primer + AKAY, mnoc=1-2, ndft=160, max=L.
        $rules[] = self::rule($substrate, 'C4', IsoDurability::LOW, PrimerType::OTHER, 1, 160, $primerOtherAkAy, $followupAkAy);
        // C4.02 — AKAY primer + AKAY, mnoc=2-3, ndft=200, max=M.
        $rules[] = self::rule($substrate, 'C4', IsoDurability::MEDIUM, PrimerType::OTHER, 2, 200, $primerOtherAkAy, $followupAkAy);
        // C4.03 — AKAY primer + AKAY, mnoc=2-4, ndft=260, max=H.
        $rules[] = self::rule($substrate, 'C4', IsoDurability::HIGH, PrimerType::OTHER, 2, 260, $primerOtherAkAy, $followupAkAy);
        // C4.04 — EP/PUR/ESI primer + EP/PUR/AY, mnoc=1-2, ndft=120, max=L.
        $rules[] = self::rule($substrate, 'C4', IsoDurability::LOW, PrimerType::OTHER, 1, 120, $primerOtherEpe, $followupEpPurAy);
        // C4.05 — EP/PUR/ESI primer + EP/PUR/AY, mnoc=2, ndft=180, max=M.
        $rules[] = self::rule($substrate, 'C4', IsoDurability::MEDIUM, PrimerType::OTHER, 2, 180, $primerOtherEpe, $followupEpPurAy);
        // C4.06 — EP/PUR/ESI primer + EP/PUR/AY, mnoc=2-3, ndft=240, max=H.
        $rules[] = self::rule($substrate, 'C4', IsoDurability::HIGH, PrimerType::OTHER, 2, 240, $primerOtherEpe, $followupEpPurAy);
        // C4.07 — EP/PUR/ESI primer + EP/PUR/AY, mnoc=2-4, ndft=300, max=VH.
        $rules[] = self::rule($substrate, 'C4', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 300, $primerOtherEpe, $followupEpPurAy);
        // C4.08 — только Zn(R) primer EP/PUR/ESI без последующих, mnoc=1, ndft=60, max=L.
        $rules[] = self::rule($substrate, 'C4', IsoDurability::LOW, PrimerType::ZINC_RICH, 1, 60, $primerZn, []);
        // C4.09 — Zn(R) primer + EP/PUR/AY, mnoc=2, ndft=160, max=M.
        $rules[] = self::rule($substrate, 'C4', IsoDurability::MEDIUM, PrimerType::ZINC_RICH, 2, 160, $primerZn, $followupEpPurAy);
        // C4.10 — Zn(R) primer + EP/PUR/AY, mnoc=2-3, ndft=200, max=H.
        $rules[] = self::rule($substrate, 'C4', IsoDurability::HIGH, PrimerType::ZINC_RICH, 2, 200, $primerZn, $followupEpPurAy);
        // C4.11 — Zn(R) primer + EP/PUR/AY, mnoc=3-4, ndft=260, max=VH.
        $rules[] = self::rule($substrate, 'C4', IsoDurability::VERY_HIGH, PrimerType::ZINC_RICH, 3, 260, $primerZn, $followupEpPurAy);

        // Таблица C.4 — категория C5.
        // C5.01 — EP/PUR/ESI primer + EP/PUR/AY, mnoc=2, ndft=180, max=L.
        $rules[] = self::rule($substrate, 'C5', IsoDurability::LOW, PrimerType::OTHER, 2, 180, $primerOtherEpe, $followupEpPurAy);
        // C5.02 — EP/PUR/ESI primer + EP/PUR/AY, mnoc=2-3, ndft=240, max=M.
        $rules[] = self::rule($substrate, 'C5', IsoDurability::MEDIUM, PrimerType::OTHER, 2, 240, $primerOtherEpe, $followupEpPurAy);
        // C5.03 — EP/PUR/ESI primer + EP/PUR/AY, mnoc=2-4, ndft=300, max=H.
        $rules[] = self::rule($substrate, 'C5', IsoDurability::HIGH, PrimerType::OTHER, 2, 300, $primerOtherEpe, $followupEpPurAy);
        // C5.04 — EP/PUR/ESI primer + EP/PUR/AY, mnoc=3-4, ndft=360, max=VH.
        $rules[] = self::rule($substrate, 'C5', IsoDurability::VERY_HIGH, PrimerType::OTHER, 3, 360, $primerOtherEpe, $followupEpPurAy);
        // C5.05 — Zn(R) primer + EP/PUR/AY, mnoc=2, ndft=160, max=L.
        $rules[] = self::rule($substrate, 'C5', IsoDurability::LOW, PrimerType::ZINC_RICH, 2, 160, $primerZn, $followupEpPurAy);
        // C5.06 — Zn(R) primer + EP/PUR/AY, mnoc=2-3, ndft=200, max=M.
        $rules[] = self::rule($substrate, 'C5', IsoDurability::MEDIUM, PrimerType::ZINC_RICH, 2, 200, $primerZn, $followupEpPurAy);
        // C5.07 — Zn(R) primer + EP/PUR/AY, mnoc=3-4, ndft=260, max=H.
        $rules[] = self::rule($substrate, 'C5', IsoDurability::HIGH, PrimerType::ZINC_RICH, 3, 260, $primerZn, $followupEpPurAy);
        // C5.08 — Zn(R) primer + EP/PUR/AY, mnoc=3-4, ndft=320, max=VH.
        $rules[] = self::rule($substrate, 'C5', IsoDurability::VERY_HIGH, PrimerType::ZINC_RICH, 3, 320, $primerZn, $followupEpPurAy);

        return $rules;
    }

    /**
     * Таблица D.1 ГОСТ 34667.5-2021 — сталь горячего цинкования по ГОСТ 9.307.
     * 20 систем G2.01..G5.05. Все правила — PrimerType::OTHER
     * (Zn(R) на цинкованном металле не применяется). Для каждой строки таблицы
     * добавлено правило с максимальной отмеченной «+» долговечностью; меньшие
     * долговечности покрываются автоматически при свёртке до сильнейших пар.
     * NDFT/MNOC — минимальные значения из ячейки «Общее число слоёв / Общий NDFT».
     * Пустой followup ([]) означает системы без последующих слоёв.
     *
     * @return list<ComplianceRule>
     */
    private static function d1Rules(): array
    {
        $substrate = Substrate::STEEL_GALVANIZED;
        $primerEpPur = [CoatingBase::EP, CoatingBase::PUR];
        $primerEpPurAy = [CoatingBase::EP, CoatingBase::PUR, CoatingBase::AY];
        $primerAy = [CoatingBase::AY];
        $followupEpPurAy = [CoatingBase::EP, CoatingBase::PUR, CoatingBase::AY];
        $followupAy = [CoatingBase::AY];

        return [
            // G2.01 — только грунт EP/PUR/AY, без последующих слоёв.
            self::rule($substrate, 'C2', IsoDurability::HIGH, PrimerType::OTHER, 1, 80, $primerEpPurAy, []),
            // G2.02 — AY primer + AY.
            self::rule($substrate, 'C2', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 160, $primerAy, $followupAy),
            // G2.03 — EP/PUR primer + EP/PUR/AY.
            self::rule($substrate, 'C2', IsoDurability::VERY_HIGH, PrimerType::OTHER, 1, 120, $primerEpPur, $followupEpPurAy),

            // G3.01 — только грунт EP/PUR/AY.
            self::rule($substrate, 'C3', IsoDurability::MEDIUM, PrimerType::OTHER, 1, 80, $primerEpPurAy, []),
            // G3.02 — EP/PUR primer + EP/PUR/AY.
            self::rule($substrate, 'C3', IsoDurability::HIGH, PrimerType::OTHER, 1, 120, $primerEpPur, $followupEpPurAy),
            // G3.03 — AY primer + AY.
            self::rule($substrate, 'C3', IsoDurability::HIGH, PrimerType::OTHER, 2, 160, $primerAy, $followupAy),
            // G3.04 — EP/PUR primer + EP/PUR/AY.
            self::rule($substrate, 'C3', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 160, $primerEpPur, $followupEpPurAy),
            // G3.05 — AY primer + AY.
            self::rule($substrate, 'C3', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 200, $primerAy, $followupAy),

            // G4.01 — только грунт EP/PUR/AY.
            self::rule($substrate, 'C4', IsoDurability::LOW, PrimerType::OTHER, 1, 80, $primerEpPurAy, []),
            // G4.02 — EP/PUR primer + EP/PUR/AY.
            self::rule($substrate, 'C4', IsoDurability::MEDIUM, PrimerType::OTHER, 1, 120, $primerEpPur, $followupEpPurAy),
            // G4.03 — AY primer + AY.
            self::rule($substrate, 'C4', IsoDurability::MEDIUM, PrimerType::OTHER, 2, 160, $primerAy, $followupAy),
            // G4.04 — EP/PUR primer + EP/PUR/AY.
            self::rule($substrate, 'C4', IsoDurability::HIGH, PrimerType::OTHER, 2, 160, $primerEpPur, $followupEpPurAy),
            // G4.05 — AY primer + AY.
            self::rule($substrate, 'C4', IsoDurability::HIGH, PrimerType::OTHER, 2, 200, $primerAy, $followupAy),
            // G4.06 — EP/PUR primer + EP/PUR/AY.
            self::rule($substrate, 'C4', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 200, $primerEpPur, $followupEpPurAy),

            // G5.01 — EP/PUR primer + EP/PUR/AY.
            self::rule($substrate, 'C5', IsoDurability::LOW, PrimerType::OTHER, 1, 120, $primerEpPur, $followupEpPurAy),
            // G5.02a — AY primer + AY.
            self::rule($substrate, 'C5', IsoDurability::LOW, PrimerType::OTHER, 2, 160, $primerAy, $followupAy),
            // G5.02b — EP/PUR primer + EP/PUR/AY.
            self::rule($substrate, 'C5', IsoDurability::MEDIUM, PrimerType::OTHER, 2, 160, $primerEpPur, $followupEpPurAy),
            // G5.03 — AY primer + AY.
            self::rule($substrate, 'C5', IsoDurability::MEDIUM, PrimerType::OTHER, 2, 200, $primerAy, $followupAy),
            // G5.04 — EP/PUR primer + EP/PUR/AY.
            self::rule($substrate, 'C5', IsoDurability::HIGH, PrimerType::OTHER, 2, 200, $primerEpPur, $followupEpPurAy),
            // G5.05 — EP/PUR primer + EP/PUR/AY.
            self::rule($substrate, 'C5', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 240, $primerEpPur, $followupEpPurAy),
        ];
    }

    /**
     * Таблица E.1 ГОСТ 34667.5-2021 — стальные поверхности с термически напылённым металлом.
     * 4 системы TSM 4.01..TSM 5.02 для категорий C4 и C5, долговечности H и VH.
     * Проникающая грунтовка EP/PUR (заполняет поры металла) + последующие слои EP/PUR.
     * Общее число слоёв = 2. primerType = OTHER (не Zn(R)).
     *
     * @return list<ComplianceRule>
     */
    private static function e1Rules(): array
    {
        $substrate = Substrate::STEEL_METALLIZED;
        $binders = [CoatingBase::EP, CoatingBase::PUR];

        return [
            // TSM 4.01
            self::rule($substrate, 'C4', IsoDurability::HIGH, PrimerType::OTHER, 2, 160, $binders, $binders),
            // TSM 4.02
            self::rule($substrate, 'C4', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 200, $binders, $binders),
            // TSM 5.01
            self::rule($substrate, 'C5', IsoDurability::HIGH, PrimerType::OTHER, 2, 200, $binders, $binders),
            // TSM 5.02
            self::rule($substrate, 'C5', IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 240, $binders, $binders),
        ];
    }

    /**
     * Таблица C.5 ГОСТ 34667.5-2021 — углеродистая сталь для трёх категорий погружения
     * Im1, Im2, Im3 (грунт и вода), долговечности H и VH, после абразивной струйной
     * обработки. 6 систем I.01..I.06 × 3 категории = 18 правил.
     *   - I.01/I.02: Zn(R) primer EP/PUR/ESI + EP/PUR
     *   - I.03/I.04: Прочие primer EP/PUR/ESI + EP/PUR
     *   - I.05/I.06: без отдельной грунтовки (первый слой EP/PUR сам служит грунтом)
     *
     * @return list<ComplianceRule>
     */
    private static function b5Rules(): array
    {
        $substrate = Substrate::STEEL_CARBON;
        $primerZn = [CoatingBase::ESI, CoatingBase::EP, CoatingBase::PUR];
        $primerEpPurEsi = [CoatingBase::EP, CoatingBase::PUR, CoatingBase::ESI];
        $primerEpPur = [CoatingBase::EP, CoatingBase::PUR];
        $followup = [CoatingBase::EP, CoatingBase::PUR];

        $rules = [];
        foreach (['Im1', 'Im2', 'Im3'] as $category) {
            // I.01 — Zn(R) primer + EP/PUR, mnoc=2-4, ndft=360, max=H.
            $rules[] = self::rule($substrate, $category, IsoDurability::HIGH, PrimerType::ZINC_RICH, 2, 360, $primerZn, $followup);
            // I.02 — Zn(R) primer + EP/PUR, mnoc=2-5, ndft=500, max=VH.
            $rules[] = self::rule($substrate, $category, IsoDurability::VERY_HIGH, PrimerType::ZINC_RICH, 2, 500, $primerZn, $followup);
            // I.03 — Прочие primer EP/PUR/ESI + EP/PUR, mnoc=2-4, ndft=380, max=H.
            $rules[] = self::rule($substrate, $category, IsoDurability::HIGH, PrimerType::OTHER, 2, 380, $primerEpPurEsi, $followup);
            // I.04 — Прочие primer EP/PUR/ESI + EP/PUR, mnoc=2-4, ndft=540, max=VH.
            $rules[] = self::rule($substrate, $category, IsoDurability::VERY_HIGH, PrimerType::OTHER, 2, 540, $primerEpPurEsi, $followup);
            // I.05 — без отдельной грунтовки, первый слой EP/PUR, mnoc=1-3, ndft=400, max=H.
            $rules[] = self::rule($substrate, $category, IsoDurability::HIGH, PrimerType::OTHER, 1, 400, $primerEpPur, $followup);
            // I.06 — без отдельной грунтовки, первый слой EP/PUR, mnoc=1-3, ndft=600, max=VH.
            $rules[] = self::rule($substrate, $category, IsoDurability::VERY_HIGH, PrimerType::OTHER, 1, 600, $primerEpPur, $followup);
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
