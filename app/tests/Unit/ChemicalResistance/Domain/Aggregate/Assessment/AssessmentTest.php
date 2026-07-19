<?php

declare(strict_types=1);

namespace App\Tests\Unit\ChemicalResistance\Domain\Aggregate\Assessment;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentNotesConsistencyValidator;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\UniqueCoatingSubstanceAssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Repository\AssessmentRepositoryInterface;
use App\ChemicalResistance\Domain\Repository\NoteRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AssessmentTest extends TestCase
{
    /** @param list<Note> $notes returned by the mocked NoteRepository::findAllByIds */
    private function spec(array $notes = []): AssessmentSpecification
    {
        $repo = $this->createMock(AssessmentRepositoryInterface::class);
        $repo->method('findByCoatingAndSubstance')->willReturn(null);

        $notesRepo = $this->createMock(NoteRepositoryInterface::class);
        $notesRepo->method('findAllByIds')->willReturn($notes);

        return new AssessmentSpecification(
            new UniqueCoatingSubstanceAssessmentSpecification($repo),
            new AssessmentNotesConsistencyValidator($notesRepo),
        );
    }

    public function test_default_max_temp_is40(): void
    {
        $a = new Assessment(
            Uuid::v4(), Uuid::v4(), Uuid::v4(),
            Grade::R, null, new StringCollection(),
            $this->spec(),
        );
        self::assertSame(40, $a->getMaxTemperature()->celsius);
    }

    public function test_explicit_max_temp(): void
    {
        $a = new Assessment(
            Uuid::v4(), Uuid::v4(), Uuid::v4(),
            Grade::R, AssessmentTemperature::fromInt(70),
            new StringCollection(),
            $this->spec(),
        );
        self::assertSame(70, $a->getMaxTemperature()->celsius);
    }

    public function test_note_ids_must_exist(): void
    {
        $noteId = Uuid::v4()->toRfc4122();
        $this->expectException(AppException::class);
        new Assessment(
            Uuid::v4(), Uuid::v4(), Uuid::v4(),
            Grade::R, null, new StringCollection($noteId),
            $this->spec([]),
        );
    }

    public function test_note_ids_success(): void
    {
        $noteId = Uuid::v4();
        $note = new Note($noteId, 'T', 'D');
        $a = new Assessment(
            Uuid::v4(), Uuid::v4(), Uuid::v4(),
            Grade::R, null, new StringCollection($noteId->toRfc4122()),
            $this->spec([$note]),
        );
        self::assertSame([$noteId->toRfc4122()], $a->getNoteIds()->getList());
    }

    public function test_note_ids_rejects_duplicates(): void
    {
        $noteId = Uuid::v4();
        $this->expectException(AppException::class);
        new Assessment(
            Uuid::v4(), Uuid::v4(), Uuid::v4(),
            Grade::R, null,
            new StringCollection($noteId->toRfc4122(), $noteId->toRfc4122()),
            $this->spec(),
        );
    }

    public function test_unique_coating_substance_specification_conflict_throws(): void
    {
        $coatingId = Uuid::v4();
        $substanceId = Uuid::v4();

        $existingId = Uuid::v4();
        $existingRepo = $this->createMock(AssessmentRepositoryInterface::class);
        $existingRepo->method('findByCoatingAndSubstance')->willReturn(null);
        $notesRepoEmpty = $this->createMock(NoteRepositoryInterface::class);
        $notesRepoEmpty->method('findAllByIds')->willReturn([]);
        $existingSpec = new AssessmentSpecification(
            new UniqueCoatingSubstanceAssessmentSpecification($existingRepo),
            new AssessmentNotesConsistencyValidator($notesRepoEmpty),
        );
        $existing = new Assessment(
            $existingId, $coatingId, $substanceId,
            Grade::R, null, new StringCollection(),
            $existingSpec,
        );

        $conflictRepo = $this->createMock(AssessmentRepositoryInterface::class);
        $conflictRepo->method('findByCoatingAndSubstance')->willReturn($existing);
        $conflictSpec = new AssessmentSpecification(
            new UniqueCoatingSubstanceAssessmentSpecification($conflictRepo),
            new AssessmentNotesConsistencyValidator($notesRepoEmpty),
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Оценка для этой пары «покрытие — вещество» уже существует.');
        new Assessment(
            Uuid::v4(), $coatingId, $substanceId,
            Grade::NR, null, new StringCollection(),
            $conflictSpec,
        );
    }
}
