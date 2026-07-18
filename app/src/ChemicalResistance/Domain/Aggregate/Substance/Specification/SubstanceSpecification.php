<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Substance\Specification;

final readonly class SubstanceSpecification
{
    public function __construct(
        public UniqueSubstanceNameSpecification $uniqueName,
        public UniqueCasSpecification $uniqueCas,
    ) {}
}
