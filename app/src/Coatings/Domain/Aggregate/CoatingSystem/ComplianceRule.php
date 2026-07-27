<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;

final readonly class ComplianceRule
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
