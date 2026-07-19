<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\ListCoatingAssessments;

final readonly class ListCoatingAssessmentsQuery
{
    public function __construct(
        public string $coatingId,
        public ?string $search = null,
        public int $page = 1,
        public int $pageSize = 50,
        public ?string $highlightSubstanceId = null,
    ) {
    }
}
