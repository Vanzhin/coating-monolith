<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetResistantCoatings;

use App\ChemicalResistance\Application\DTO\ResistantCoatingDTO;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Repository\AssessmentRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Оценки химстойкости по веществу. Порядок: стойкие вперёд (R → LR → прочие).
 * Вердикт (grade) и maxTemperature — из assessment'а (единый источник правды).
 */
class GetResistantCoatingsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly AssessmentRepositoryInterface $assessments,
    ) {
    }

    /**
     * @return list<ResistantCoatingDTO>
     */
    public function __invoke(GetResistantCoatingsQuery $query): array
    {
        $rows = [];
        foreach ($this->assessments->findAllBySubstance(Uuid::fromString($query->substanceId)) as $a) {
            $grade = $a->getGrade();
            if (!$query->includeAll && !$grade->isSuitable()) {
                continue;
            }
            $rows[] = new ResistantCoatingDTO(
                coatingId: $a->getCoatingId()->toRfc4122(),
                grade: $grade->value,
                gradeLabel: $grade->label(),
                maxTemperature: $a->getMaxTemperature()->celsius,
                assessmentId: $a->getId(),
                suitable: $grade->isSuitable(),
            );
        }

        usort($rows, fn (ResistantCoatingDTO $x, ResistantCoatingDTO $y) => $this->weight(Grade::from($x->grade)) <=> $this->weight(Grade::from($y->grade)));

        return $rows;
    }

    /** Порядок вывода: стойкие вперёд. */
    private function weight(Grade $grade): int
    {
        return match ($grade) {
            Grade::R => 0,
            Grade::LR => 1,
            Grade::NR => 2,
            default => 3,
        };
    }
}
