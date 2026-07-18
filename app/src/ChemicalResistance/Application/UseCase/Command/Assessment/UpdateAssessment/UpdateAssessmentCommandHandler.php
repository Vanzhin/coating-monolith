<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Command\Assessment\UpdateAssessment;

use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Repository\AssessmentRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final class UpdateAssessmentCommandHandler
{
    public function __construct(private AssessmentRepository $assessments) {}

    public function __invoke(UpdateAssessmentCommand $c): void
    {
        $a = $this->assessments->find(Uuid::fromString($c->id))
            ?? throw new AppException('Оценка не найдена.');

        $a->setGrade(Grade::from($c->grade));
        $a->setMaxTemperature(
            $c->maxTemperatureCelsius !== null
                ? AssessmentTemperature::fromInt($c->maxTemperatureCelsius)
                : AssessmentTemperature::default(),
        );
        $a->setNoteIds(new StringCollection(...$c->noteIds));
        $this->assessments->save($a);
    }
}
