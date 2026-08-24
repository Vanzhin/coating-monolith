<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Facet;

use App\Coatings\Domain\Compliance\ComplianceStandard;

/**
 * Самоописание стандарта для UI и поиска: подписи и варианты двух осей маркировки, компактная
 * подпись бейджа и «≥»-раскрытие значений для фильтра. Каждый стандарт реализует своё поведение
 * (у ISO — семья категорий и порядок долговечности; у СП — порядок степеней и точное совпадение
 * условий), поэтому фильтр/показ работают единообразно, без ветвления по стандарту.
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
     * Хранимые значения первой оси, удовлетворяющие фильтру на $value (ISO: ≥ в семье; СП: ≥ по рангу).
     *
     * @return list<string>
     */
    public function expandPrimary(string $value): array;

    /**
     * Хранимые значения второй оси, удовлетворяющие фильтру (ISO: ≥ долговечность; СП: точное условие).
     *
     * @return list<string>
     */
    public function expandSecondary(string $value): array;
}
