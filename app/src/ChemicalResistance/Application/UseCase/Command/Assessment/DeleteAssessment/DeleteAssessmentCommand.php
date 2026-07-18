<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Command\Assessment\DeleteAssessment;

final readonly class DeleteAssessmentCommand
{
    public function __construct(public string $id) {}
}
