<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Assessment;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

class Assessment extends Aggregate
{
    public readonly Uuid $id;
    private Uuid $coatingId;
    private Uuid $substanceId;
    private Grade $grade;
    private AssessmentTemperature $maxTemperature;
    private StringCollection $noteIds;
    private AssessmentSpecification $specification;

    public function __construct(
        Uuid $id,
        Uuid $coatingId,
        Uuid $substanceId,
        Grade $grade,
        ?AssessmentTemperature $maxTemperature,
        StringCollection $noteIds,
        AssessmentSpecification $specification,
        NoteRepository $notesForConsistency,
    ) {
        $this->id = $id;
        $this->coatingId = $coatingId;
        $this->substanceId = $substanceId;
        $this->specification = $specification;

        $this->grade = $grade;
        $this->maxTemperature = $maxTemperature ?? AssessmentTemperature::default();
        $this->setNoteIds($noteIds, $notesForConsistency);
        $this->specification->uniqueCoatingSubstance->satisfy($this);
    }

    public function getId(): string { return $this->id->toRfc4122(); }
    public function getCoatingId(): Uuid { return $this->coatingId; }
    public function getSubstanceId(): Uuid { return $this->substanceId; }
    public function getGrade(): Grade { return $this->grade; }
    public function getMaxTemperature(): AssessmentTemperature { return $this->maxTemperature; }
    public function getNoteIds(): StringCollection { return $this->noteIds; }

    public function setGrade(Grade $g): void { $this->grade = $g; }
    public function setMaxTemperature(AssessmentTemperature $t): void { $this->maxTemperature = $t; }

    public function setNoteIds(StringCollection $ids, NoteRepository $notes): void
    {
        $this->specification->notesConsistency->validate($ids, $notes);
        $this->noteIds = $ids;
    }
}
