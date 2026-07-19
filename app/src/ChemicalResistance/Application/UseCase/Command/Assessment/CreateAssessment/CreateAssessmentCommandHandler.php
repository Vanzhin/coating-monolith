<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Command\Assessment\CreateAssessment;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\ChemicalResistance\Domain\Repository\AssessmentRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

final class CreateAssessmentCommandHandler
{
    public function __construct(
        private AssessmentRepositoryInterface $assessments,
        private AssessmentSpecification $specification,
    ) {}

    public function __invoke(CreateAssessmentCommand $c): string
    {
        $maxTemp = $c->maxTemperatureCelsius !== null
            ? AssessmentTemperature::fromInt($c->maxTemperatureCelsius) : null;

        $a = new Assessment(
            Uuid::v4(),
            Uuid::fromString($c->coatingId),
            Uuid::fromString($c->substanceId),
            Grade::from($c->grade),
            $maxTemp,
            new StringCollection(...$c->noteIds),
            $this->specification,
        );
        $this->assessments->add($a);
        return $a->getId();
    }
}
