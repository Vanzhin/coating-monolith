<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\DTO;

// Read-side view of a row in the modal's chemical-resistance table.
final readonly class AssessmentRowDTO
{
    public function __construct(
        public string  $substanceId,
        public string  $canonicalName,
        public ?string $cas,
        /** @var list<string> */
        public array   $aliases,
        public string  $grade,
        public int     $maxTemperatureCelsius,
        /** @var list<array{title:string,description:string,isSystem:bool}> */
        public array   $notes,
        public ?string $assessmentId = null,
        /** @var list<string> note IDs */
        public array   $noteIds = [],
    ) {}
}
