<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Command\Assessment\DeleteAssessment;

use App\ChemicalResistance\Domain\Repository\AssessmentRepository;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final class DeleteAssessmentCommandHandler
{
    public function __construct(private AssessmentRepository $assessments) {}

    public function __invoke(DeleteAssessmentCommand $c): void
    {
        $a = $this->assessments->find(Uuid::fromString($c->id))
            ?? throw new AppException('Оценка не найдена.');
        $this->assessments->remove($a);
    }
}
