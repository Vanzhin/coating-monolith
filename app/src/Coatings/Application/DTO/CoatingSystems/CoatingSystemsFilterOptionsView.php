<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

/**
 * Полное дерево вариантов фильтра систем для построения формы во ВНЕШНЕМ контексте (Inquiries:
 * правка распознанных параметров). Категория/долговечность зависят от стандарта — отдаём вложенно,
 * чтобы потребитель строил каскад без знания домена Coatings. Вещества сюда не входят (их
 * typeahead/by-ids — у ChemicalResistance).
 */
final readonly class CoatingSystemsFilterOptionsView
{
    /**
     * @param list<FilterOption>          $substrates
     * @param list<FilterOption>          $environments
     * @param list<FilterOption>          $thermalEnvironments
     * @param list<StandardFilterOptions> $standards
     */
    public function __construct(
        public array $substrates,
        public array $environments,
        public array $thermalEnvironments,
        public array $standards,
    ) {
    }
}
