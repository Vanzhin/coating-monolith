<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Command\Assessment\CreateAssessment;

final readonly class CreateAssessmentCommand implements \App\Shared\Application\Command\CommandInterface
{
    /** @param list<string> $noteIds */
    public function __construct(
        public string $coatingId,
        public string $substanceId,
        public string $grade,
        public ?int $maxTemperatureCelsius,
        public array $noteIds,
    ) {}
}
