<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\Service;

final readonly class ImportReport
{
    /**
     * @param list<string> $conflicts
     * @param list<string> $warnings
     */
    public function __construct(
        public int $substancesCreated,
        public int $substancesReused,
        public int $aliasesAdded,
        public int $assessmentsCreated,
        public int $assessmentsUpdated,
        public int $notesCreated,
        public array $conflicts,
        public array $warnings,
    ) {}
}
