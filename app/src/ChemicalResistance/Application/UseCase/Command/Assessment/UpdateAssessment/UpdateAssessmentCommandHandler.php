<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Command\Assessment\UpdateAssessment;

use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Repository\AssessmentRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;

final class UpdateAssessmentCommandHandler implements CommandHandlerInterface
{
    public function __construct(private AssessmentRepositoryInterface $assessments)
    {
    }

    public function __invoke(UpdateAssessmentCommand $c): void
    {
        $a = $this->assessments->findOneById($c->id)
            ?? throw new AppException('Оценка не найдена.');

        $a->setGrade(Grade::from($c->grade));
        $a->setMaxTemperature(
            null !== $c->maxTemperatureCelsius
                ? AssessmentTemperature::fromInt($c->maxTemperatureCelsius)
                : AssessmentTemperature::default(),
        );
        $a->setNoteIds(new StringCollection(...$c->noteIds));
        $this->assessments->add($a);
    }
}
