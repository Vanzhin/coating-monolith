<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Command\Assessment\UpdateAssessment;

final readonly class UpdateAssessmentCommand implements \App\Shared\Application\Command\CommandInterface
{
    /** @param list<string> $noteIds */
    public function __construct(
        public string $id,
        public string $grade,
        public ?int $maxTemperatureCelsius,
        public array $noteIds,
    ) {}
}
