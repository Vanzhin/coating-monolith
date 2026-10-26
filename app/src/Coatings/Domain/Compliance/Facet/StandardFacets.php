<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Facet;

use App\Coatings\Domain\Compliance\ComplianceStandard;

/**
 * Самоописание стандарта для UI: подписи и варианты двух осей маркировки, компактная подпись
 * бейджа и признак наличия второй оси. Матч значений в поиске — точный (минимально достаточная
 * система), раскрытия значений нет; показ/фильтр работают единообразно, без ветвления по стандарту.
 */
interface StandardFacets
{
    public function standard(): ComplianceStandard;

    public function primaryLabel(): string;

    public function secondaryLabel(): string;

    /**
     * @return list<FacetOption> варианты первой оси (ISO: категория; СП: среда)
     */
    public function primaryOptions(): array;

    /**
     * @return list<FacetOption> варианты второй оси (ISO: долговечность; СП: условия)
     */
    public function secondaryOptions(): array;

    /**
     * Компактная подпись бейджа по паре хранимых значений (ISO: «C3-H»; СП: «Среднеагр. · откр. воздух»).
     */
    public function badgeLabel(string $primary, ?string $secondary): string;

    /**
     * Есть ли у данного значения первой оси вторая ось выбора. ISO: у CX/Im4 (ГОСТ 34667.9)
     * долговечность всегда высокая — ось скрывается и в фильтре не участвует. СП: условия есть всегда.
     */
    public function hasSecondaryAxis(string $primary): bool;
}
