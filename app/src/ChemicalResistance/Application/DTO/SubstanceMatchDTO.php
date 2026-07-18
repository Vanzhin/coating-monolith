<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\DTO;

// For CoatingDTO.matchedSubstances (list-card badge).
final readonly class SubstanceMatchDTO
{
    public function __construct(
        public string $substanceId,
        public string $canonicalName,
        public string $matchedVia,   // 'canonical' | 'alias' | 'cas'
    ) {}
}
