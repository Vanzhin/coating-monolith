<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Assessment\Specification;

use App\Shared\Domain\Specification\SpecificationInterface;

final readonly class AssessmentSpecification implements SpecificationInterface
{
    public function __construct(
        public UniqueCoatingSubstanceAssessmentSpecification $uniqueCoatingSubstance,
        public AssessmentNotesConsistencyValidator $notesConsistency,
    ) {}
}
