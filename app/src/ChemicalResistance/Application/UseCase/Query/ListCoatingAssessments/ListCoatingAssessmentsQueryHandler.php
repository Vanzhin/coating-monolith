<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Query\ListCoatingAssessments;

use App\ChemicalResistance\Application\DTO\AssessmentRowDTO;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Repository\AssessmentRepository;
use App\ChemicalResistance\Domain\Repository\SubstanceRepository;
use App\ChemicalResistance\Domain\Service\EffectiveAssessmentNotes;
use App\ChemicalResistance\Domain\Service\NoteView;
use Symfony\Component\Uid\Uuid;

final class ListCoatingAssessmentsQueryHandler
{
    public function __construct(
        private AssessmentRepository   $assessments,
        private SubstanceRepository    $substances,
        private EffectiveAssessmentNotes $effectiveNotes,
    ) {}

    public function __invoke(ListCoatingAssessmentsQuery $q): CoatingAssessmentsPage
    {
        $cid  = Uuid::fromString($q->coatingId);
        $page = $this->assessments->paginateByCoating($cid, $q->search, $q->page, $q->pageSize);

        /** @var list<string> $substanceIds */
        $substanceIds = array_map(
            fn(Assessment $a) => $a->getSubstanceId()->toRfc4122(),
            $page->items,
        );

        $subs = $this->substances->findAllByIds($substanceIds);
        $subById = [];
        foreach ($subs as $s) {
            $subById[$s->getId()] = $s;
        }

        $rows = [];
        foreach ($page->items as $a) {
            $s = $subById[$a->getSubstanceId()->toRfc4122()] ?? null;
            if ($s === null) {
                continue;
            }
            $noteViews = $this->effectiveNotes->of($a);
            $rows[] = new AssessmentRowDTO(
                substanceId: $s->getId(),
                canonicalName: $s->getCanonicalName(),
                cas: $s->getCas()?->value,
                aliases: $s->getAliases()->getList(),
                grade: $a->getGrade()->value,
                maxTemperatureCelsius: $a->getMaxTemperature()->celsius,
                notes: array_map(
                    fn(NoteView $n) => [
                        'title'       => $n->title,
                        'description' => $n->description,
                        'isSystem'    => $n->isSystem,
                    ],
                    $noteViews,
                ),
                assessmentId: $a->getId(),
                noteIds: $a->getNoteIds()->getList(),
            );
        }

        $counts = $this->assessments->countByCoatingGroupedByGrade($cid);

        return new CoatingAssessmentsPage(
            rows:       $rows,
            total:      $page->total,
            countR:     $counts['R'] ?? 0,
            countLR:    $counts['LR'] ?? 0,
            countOther: ($counts['NR'] ?? 0) + ($counts['FS'] ?? 0) + ($counts['NT'] ?? 0),
        );
    }
}
