<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Command\Assessment\DeleteAssessment;

final readonly class DeleteAssessmentCommand implements \App\Shared\Application\Command\CommandInterface
{
    public function __construct(public string $id) {}
}
