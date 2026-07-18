<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\Service;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentNotesConsistencyValidator;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\UniqueCoatingSubstanceAssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Repository\AssessmentRepository;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
use App\ChemicalResistance\Infrastructure\Docx\DocxParseResult;
use App\ChemicalResistance\Infrastructure\Docx\GradeCellParser;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final class ChemicalResistanceImporter
{
    public function __construct(
        private SubstanceLookup $lookup,
        private NoteRepository $notes,
        private AssessmentRepository $assessments,
        private GradeCellParser $gradeParser,
    ) {}

    public function import(DocxParseResult $parsed, Uuid $coatingId, ImportOptions $opts): ImportReport
    {
        $subCreated    = 0;
        $subReused     = 0;
        $aliasesAdded  = 0;
        $assCreated    = 0;
        $assUpdated    = 0;
        $notesCreated  = 0;
        $conflicts     = [];
        $warnings      = [];

        // 1. Notes: create one Note per ParsedNote, build label→id map.
        $labelToId = [];
        foreach ($parsed->notes as $pn) {
            $note = new Note(Uuid::v4(), $pn->title, $pn->description);
            if (!$opts->dryRun) {
                $this->notes->save($note);
            }
            $labelToId[$pn->label] = $note->getId();
            $notesCreated++;
        }

        // 2. Rows.
        foreach ($parsed->rows as $row) {
            // Parse grade cell — failures are non-fatal warnings.
            try {
                $g = $this->gradeParser->parse($row->gradeCell);
            } catch (AppException $e) {
                $warnings[] = sprintf('«%s»: %s', $row->substanceName, $e->getMessage());
                continue;
            }

            // Substance: distinguish created vs reused.
            $preexisted = $this->lookup->findByNormalizedName($row->substanceName);
            $sub = $this->lookup->findOrCreateByName($row->substanceName, persist: !$opts->dryRun);
            if ($preexisted === null) {
                $subCreated++;
            } else {
                $subReused++;
                // Count alias additions: if the preexisted substance didn't have this
                // exact raw name before findOrCreateByName was called, an alias was added.
                if (!$preexisted->hasName($row->substanceName)) {
                    $aliasesAdded++;
                }
            }

            // Resolve note labels to IDs; silently drop unknown labels.
            $resolvedNoteIds = [];
            foreach ($g->noteLabels as $label) {
                if (isset($labelToId[$label])) {
                    $resolvedNoteIds[] = $labelToId[$label];
                } else {
                    $warnings[] = sprintf(
                        '«%s»: ссылка «%s» не найдена в примечаниях, пропущена.',
                        $row->substanceName,
                        $label,
                    );
                }
            }
            $noteIds = new StringCollection(...$resolvedNoteIds);

            $maxTemp = $g->maxTemperatureCelsius !== null
                ? AssessmentTemperature::fromInt($g->maxTemperatureCelsius)
                : AssessmentTemperature::fromInt($opts->defaultMaxTemp);

            $substanceId = Uuid::fromString($sub->getId());
            $existing = $this->assessments->findByCoatingAndSubstance($coatingId, $substanceId);

            if ($existing !== null && !$opts->overwrite) {
                $conflicts[] = sprintf('«%s»: оценка уже существует, пропущено.', $row->substanceName);
                continue;
            }

            if ($existing !== null) {
                // Overwrite: update in place.
                $existing->setGrade(Grade::from($g->grade));
                $existing->setMaxTemperature($maxTemp);
                $existing->setNotesRepositoryForConsistency($this->notes);
                $existing->setNoteIds($noteIds);
                if (!$opts->dryRun) {
                    $this->assessments->save($existing);
                }
                $assUpdated++;
            } else {
                $a = new Assessment(
                    Uuid::v4(),
                    $coatingId,
                    $substanceId,
                    Grade::from($g->grade),
                    $maxTemp,
                    $noteIds,
                    new AssessmentSpecification(
                        new UniqueCoatingSubstanceAssessmentSpecification($this->assessments),
                        new AssessmentNotesConsistencyValidator(),
                    ),
                    $this->notes,
                );
                if (!$opts->dryRun) {
                    $this->assessments->save($a);
                }
                $assCreated++;
            }
        }

        return new ImportReport(
            substancesCreated: $subCreated,
            substancesReused: $subReused,
            aliasesAdded: $aliasesAdded,
            assessmentsCreated: $assCreated,
            assessmentsUpdated: $assUpdated,
            notesCreated: $notesCreated,
            conflicts: $conflicts,
            warnings: $warnings,
        );
    }
}
