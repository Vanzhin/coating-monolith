<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Domain\Aggregate\Assessment;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

class Assessment extends Aggregate
{
    public readonly Uuid $id;
    private Uuid $coatingId;
    private Uuid $substanceId;
    /** Backing scalar; getter returns Grade enum. */
    private string $grade;
    /** Backing scalar; getter returns AssessmentTemperature VO. */
    private int $maxTemperatureCelsius;
    private StringCollection $noteIds;

    /**
     * Инжектится через InitSpecificationOnPostLoadListener на postLoad
     * или через конструктор при создании новой оценки. Хранит только
     * ссылку на bag со специализациями — репозитории живут внутри самих
     * специализаций.
     */
    private AssessmentSpecification $specification;

    public function __construct(
        Uuid $id,
        Uuid $coatingId,
        Uuid $substanceId,
        Grade $grade,
        ?AssessmentTemperature $maxTemperature,
        StringCollection $noteIds,
        AssessmentSpecification $specification,
    ) {
        $this->id = $id;
        $this->coatingId = $coatingId;
        $this->substanceId = $substanceId;
        $this->specification = $specification;

        $this->grade = $grade->value;
        $this->maxTemperatureCelsius = ($maxTemperature ?? AssessmentTemperature::default())->celsius;
        $this->setNoteIds($noteIds);
        $this->specification->uniqueCoatingSubstance->satisfy($this);
    }

    public function getId(): string
    {
        return $this->id->toRfc4122();
    }

    public function getCoatingId(): Uuid
    {
        return $this->coatingId;
    }

    public function getSubstanceId(): Uuid
    {
        return $this->substanceId;
    }

    public function getGrade(): Grade
    {
        return Grade::from($this->grade);
    }

    public function getMaxTemperature(): AssessmentTemperature
    {
        return AssessmentTemperature::fromInt($this->maxTemperatureCelsius);
    }

    public function getNoteIds(): StringCollection
    {
        return $this->noteIds;
    }

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
        $this->specification->notesConsistency->validate($ids);
        $this->noteIds = $ids;
    }
}
