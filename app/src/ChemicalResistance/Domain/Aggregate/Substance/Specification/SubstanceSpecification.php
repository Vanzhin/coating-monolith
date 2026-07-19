<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Substance\Specification;

use App\Shared\Domain\Specification\SpecificationInterface;

final readonly class SubstanceSpecification implements SpecificationInterface
{
    public function __construct(
        public UniqueSubstanceNameSpecification $uniqueName,
        public UniqueCasSpecification $uniqueCas,
    ) {}
}
