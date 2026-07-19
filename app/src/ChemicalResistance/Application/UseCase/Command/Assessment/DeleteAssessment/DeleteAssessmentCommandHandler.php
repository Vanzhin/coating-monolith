<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Command\Assessment\DeleteAssessment;

use App\ChemicalResistance\Domain\Repository\AssessmentRepositoryInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final class DeleteAssessmentCommandHandler
{
    public function __construct(private AssessmentRepositoryInterface $assessments) {}

    public function __invoke(DeleteAssessmentCommand $c): void
    {
        $a = $this->assessments->findOneById($c->id)
            ?? throw new AppException('Оценка не найдена.');
        $this->assessments->remove($a);
    }
}
