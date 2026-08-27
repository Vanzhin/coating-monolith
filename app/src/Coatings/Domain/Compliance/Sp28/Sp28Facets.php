<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Sp28;

use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Coatings\Domain\Compliance\Facet\FacetOption;
use App\Coatings\Domain\Compliance\Facet\StandardFacets;

/**
 * Самоописание СП 28.13330: ось «среда» (степень агрессивности, порядковая «≥») и «условия
 * эксплуатации» (номинальная, точное совпадение). NON_AGGRESSIVE наружу не выводится.
 */
final readonly class Sp28Facets implements StandardFacets
{
    public function standard(): ComplianceStandard
    {
        return ComplianceStandard::SP_28;
    }

    public function primaryLabel(): string
    {
        return 'Среда';
    }

    public function secondaryLabel(): string
    {
        return 'Условия эксплуатации';
    }

    public function primaryOptions(): array
    {
        return [
            new FacetOption(SpAggressivity::WEAK->value, SpAggressivity::WEAK->title()),
            new FacetOption(SpAggressivity::MEDIUM->value, SpAggressivity::MEDIUM->title()),
            new FacetOption(SpAggressivity::STRONG->value, SpAggressivity::STRONG->title()),
        ];
    }

    public function secondaryOptions(): array
    {
        return array_map(
            static fn (SpExploitation $e): FacetOption => new FacetOption($e->value, $e->title()),
            SpExploitation::cases(),
        );
    }

    public function badgeLabel(string $primary, ?string $secondary): string
    {
        $degree = SpAggressivity::from($primary)->shortTitle();
        $condition = null !== $secondary ? SpExploitation::from($secondary)->shortTitle() : null;

        return null !== $condition ? $degree.' · '.$condition : $degree;
    }

    public function hasSecondaryAxis(string $primary): bool
    {
        return true; // у СП условия эксплуатации есть для любой степени агрессивности
    }

    public function expandPrimary(string $value): array
    {
        return SpAggressivity::tryFrom($value)?->atOrAbove() ?? [$value];
    }

    public function expandSecondary(string $value): array
    {
        // Условия эксплуатации — номинальная ось: точное совпадение.
        return [$value];
    }
}
