<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Iso12944;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Compliance\ComplianceStandard;

final readonly class Iso12944Rule
{
    /**
     * @param list<CoatingBase> $primerBinders
     * @param list<CoatingBase> $otherBinders
     */
    public function __construct(
        public ComplianceStandard $standard,
        public Substrate $substrate,
        public string $category,
        public string $durability,
        public PrimerType $primerType,
        public int $mnoc,
        public int $ndft,
        public array $primerBinders,
        public array $otherBinders,
    ) {
    }
}
