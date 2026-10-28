<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingSystemsFilterOptions;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterOptionsView;
use App\Coatings\Application\DTO\CoatingSystems\FilterOption;
use App\Coatings\Application\DTO\CoatingSystems\StandardFilterOptions;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Compliance\ComplianceFacetsRegistry;
use App\Coatings\Domain\Compliance\Facet\FacetOption;
use App\Coatings\Domain\Compliance\Facet\StandardFacets;
use App\Coatings\Domain\Repository\ThermalEnvironment;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Собирает дерево вариантов из доменных enum-ов и реестра фасет. Порядок осей и признак «нет второй
 * оси» — как в самоописании стандарта (StandardFacets), чтобы форма повторяла логику поиска.
 */
final readonly class GetCoatingSystemsFilterOptionsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ComplianceFacetsRegistry $facetsRegistry)
    {
    }

    public function __invoke(GetCoatingSystemsFilterOptionsQuery $query): CoatingSystemsFilterOptionsView
    {
        return new CoatingSystemsFilterOptionsView(
            substrates: array_map(static fn (Substrate $s): FilterOption => new FilterOption($s->value, $s->title()), Substrate::cases()),
            environments: array_map(static fn (EnvironmentType $e): FilterOption => new FilterOption($e->value, $e->title()), EnvironmentType::cases()),
            thermalEnvironments: array_map(static fn (ThermalEnvironment $t): FilterOption => new FilterOption($t->value, $t->title()), ThermalEnvironment::cases()),
            standards: array_map($this->standardOptions(...), $this->facetsRegistry->all()),
        );
    }

    private function standardOptions(StandardFacets $facets): StandardFilterOptions
    {
        $categories = $facets->primaryOptions();
        $withoutSecondary = [];
        foreach ($categories as $category) {
            if (!$facets->hasSecondaryAxis($category->value)) {
                $withoutSecondary[] = $category->value;
            }
        }

        return new StandardFilterOptions(
            value: $facets->standard()->value,
            label: $facets->standard()->title(),
            primaryLabel: $facets->primaryLabel(),
            secondaryLabel: $facets->secondaryLabel(),
            categories: array_map($this->toOption(...), $categories),
            durabilities: array_map($this->toOption(...), $facets->secondaryOptions()),
            categoriesWithoutSecondaryAxis: $withoutSecondary,
        );
    }

    private function toOption(FacetOption $option): FilterOption
    {
        return new FilterOption($option->value, $option->title);
    }
}
