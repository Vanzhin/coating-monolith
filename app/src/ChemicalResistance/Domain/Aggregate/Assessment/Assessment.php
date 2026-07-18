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
    /** Backing field stored as string in DB; getter returns Grade enum. */
    private string $grade;
    /** Backing field stored as smallint in DB; getter returns AssessmentTemperature VO. */
    private int $maxTemperatureCelsius;
    private StringCollection $noteIds;
    private ?AssessmentSpecification $specification = null;
    private ?NoteRepository $notesForConsistency = null;

    public function __construct(
        Uuid $id,
        Uuid $coatingId,
        Uuid $substanceId,
        Grade $grade,
        ?AssessmentTemperature $maxTemperature,
        StringCollection $noteIds,
        ?AssessmentSpecification $specification = null,
        ?NoteRepository $notesForConsistency = null,
    ) {
        $this->id = $id;
        $this->coatingId = $coatingId;
        $this->substanceId = $substanceId;
        $this->specification = $specification;
        $this->notesForConsistency = $notesForConsistency;

        $this->grade = $grade->value;
        $this->maxTemperatureCelsius = ($maxTemperature ?? AssessmentTemperature::default())->celsius;
        $this->setNoteIds($noteIds);
        if (isset($this->specification)) {
            $this->specification->uniqueCoatingSubstance->satisfy($this);
        }
    }

    public function setSpecification(AssessmentSpecification $spec): void
    {
        $this->specification = $spec;
    }

    public function setNotesRepositoryForConsistency(NoteRepository $notes): void
    {
        $this->notesForConsistency = $notes;
    }

    public function getId(): string { return $this->id->toRfc4122(); }
    public function getCoatingId(): Uuid { return $this->coatingId; }
    public function getSubstanceId(): Uuid { return $this->substanceId; }
    public function getGrade(): Grade { return Grade::from($this->grade); }
    public function getMaxTemperature(): AssessmentTemperature { return AssessmentTemperature::fromInt($this->maxTemperatureCelsius); }
    public function getNoteIds(): StringCollection { return $this->noteIds; }

    public function setGrade(Grade $g): void
    {
        $this->grade = $g->value;
    }

    public function setMaxTemperature(AssessmentTemperature $t): void
    {
        $this->maxTemperatureCelsius = $t->celsius;
    }

    public function setNoteIds(StringCollection $ids): void
    {
        if (isset($this->specification) && isset($this->notesForConsistency)) {
            $this->specification->notesConsistency->validate($ids, $this->notesForConsistency);
        }
        $this->noteIds = $ids;
    }
}
