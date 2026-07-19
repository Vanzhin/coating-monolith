<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\ListCoatingAssessments;

use App\ChemicalResistance\Application\DTO\AssessmentRowDTO;

final readonly class CoatingAssessmentsPage
{
    public function __construct(
        /** @var list<AssessmentRowDTO> */
        public array $rows,
        public int $total,
        public int $countR,
        public int $countLR,
        public int $countOther,
    ) {
    }
}
