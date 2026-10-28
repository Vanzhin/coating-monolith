<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

/**
 * Варианты двух осей маркировки одного стандарта для формы фильтра. primaryLabel/secondaryLabel —
 * подписи осей (ISO: категория/долговечность; СП: среда/условия). categoriesWithoutSecondaryAxis —
 * значения первой оси, у которых второй оси нет (ISO: CX/Im4 — долговечность не выбирается).
 */
final readonly class StandardFilterOptions
{
    /**
     * @param list<FilterOption> $categories                     варианты первой оси
     * @param list<FilterOption> $durabilities                   варианты второй оси
     * @param list<string>       $categoriesWithoutSecondaryAxis
     */
    public function __construct(
        public string $value,
        public string $label,
        public string $primaryLabel,
        public string $secondaryLabel,
        public array $categories,
        public array $durabilities,
        public array $categoriesWithoutSecondaryAxis,
    ) {
    }
}
