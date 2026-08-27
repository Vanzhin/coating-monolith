<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Iso12944;

use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Coatings\Domain\Compliance\Facet\FacetOption;
use App\Coatings\Domain\Compliance\Facet\StandardFacets;

/**
 * Самоописание ISO 12944: ось «категория коррозивности» (семейная «≥») и «долговечность» (порядковая «≥»).
 */
final readonly class Iso12944Facets implements StandardFacets
{
    public function standard(): ComplianceStandard
    {
        return ComplianceStandard::ISO_12944;
    }

    public function primaryLabel(): string
    {
        return 'Категория коррозивности';
    }

    public function secondaryLabel(): string
    {
        return 'Долговечность';
    }

    public function primaryOptions(): array
    {
        return array_map(
            static fn (IsoCorrosivityCategory $c): FacetOption => new FacetOption($c->value, $c->title().' — '.$c->description()),
            IsoCorrosivityCategory::cases(),
        );
    }

    public function secondaryOptions(): array
    {
        return array_map(
            static fn (IsoDurability $d): FacetOption => new FacetOption($d->value, $d->title().' — '.$d->description()),
            IsoDurability::cases(),
        );
    }

    public function badgeLabel(string $primary, ?string $secondary): string
    {
        $durability = null !== $secondary ? IsoDurability::tryFrom($secondary) : null;

        return $primary.(null !== $durability ? '-'.$durability->title() : '');
    }

    public function hasSecondaryAxis(string $primary): bool
    {
        // CX и Im4 (ГОСТ 34667.9) — только высокая долговечность, оси выбора нет.
        return !in_array(
            IsoCorrosivityCategory::tryFrom($primary),
            [IsoCorrosivityCategory::CX, IsoCorrosivityCategory::IM4],
            true,
        );
    }

    public function expandPrimary(string $value): array
    {
        return IsoCorrosivityCategory::tryFrom($value)?->atOrAboveInFamily() ?? [$value];
    }

    public function expandSecondary(string $value): array
    {
        return IsoDurability::tryFrom($value)?->atOrAbove() ?? [$value];
    }
}
