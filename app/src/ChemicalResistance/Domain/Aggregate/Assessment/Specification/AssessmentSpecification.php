<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Assessment\Specification;

final readonly class AssessmentSpecification
{
    public function __construct(
        public UniqueCoatingSubstanceAssessmentSpecification $uniqueCoatingSubstance,
        public AssessmentNotesConsistencyValidator $notesConsistency,
    ) {}
}
