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
use App\ChemicalResistance\Domain\Repository\AssessmentRepository;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AssessmentTest extends TestCase
{
    private function spec(): AssessmentSpecification
    {
        $repo = $this->createMock(AssessmentRepository::class);
        $repo->method('findByCoatingAndSubstance')->willReturn(null);
        return new AssessmentSpecification(
            new UniqueCoatingSubstanceAssessmentSpecification($repo),
            new AssessmentNotesConsistencyValidator(),
        );
    }

    private function notesRepoWith(array $notes): NoteRepository
    {
        $r = $this->createMock(NoteRepository::class);
        $r->method('findAllByIds')->willReturn($notes);
        return $r;
    }

    public function testDefaultMaxTempIs40(): void
    {
        $a = new Assessment(
            Uuid::v4(), Uuid::v4(), Uuid::v4(),
            Grade::R, null, new StringCollection(),
            $this->spec(), $this->notesRepoWith([]),
        );
        self::assertSame(40, $a->getMaxTemperature()->celsius);
    }

    public function testExplicitMaxTemp(): void
    {
        $a = new Assessment(
            Uuid::v4(), Uuid::v4(), Uuid::v4(),
            Grade::R, AssessmentTemperature::fromInt(70),
            new StringCollection(),
            $this->spec(), $this->notesRepoWith([]),
        );
        self::assertSame(70, $a->getMaxTemperature()->celsius);
    }

    public function testNoteIdsMustExist(): void
    {
        $noteId = Uuid::v4()->toRfc4122();
        // Note repo returns empty — id does not exist.
        $this->expectException(AppException::class);
        new Assessment(
            Uuid::v4(), Uuid::v4(), Uuid::v4(),
            Grade::R, null, new StringCollection($noteId),
            $this->spec(), $this->notesRepoWith([]),
        );
    }

    public function testNoteIdsSuccess(): void
    {
        $noteId = Uuid::v4();
        $note = new Note($noteId, 'T', 'D');
        $a = new Assessment(
            Uuid::v4(), Uuid::v4(), Uuid::v4(),
            Grade::R, null, new StringCollection($noteId->toRfc4122()),
            $this->spec(), $this->notesRepoWith([$note]),
        );
        self::assertSame([$noteId->toRfc4122()], $a->getNoteIds()->getList());
    }

    public function testNoteIdsRejectsDuplicates(): void
    {
        $noteId = Uuid::v4();
        $note = new Note($noteId, 'T', 'D');
        $this->expectException(AppException::class);
        new Assessment(
            Uuid::v4(), Uuid::v4(), Uuid::v4(),
            Grade::R, null,
            new StringCollection($noteId->toRfc4122(), $noteId->toRfc4122()),
            $this->spec(), $this->notesRepoWith([$note]),
        );
    }

    public function testUniqueCoatingSubstanceSpecificationConflictThrows(): void
    {
        $coatingId = Uuid::v4();
        $substanceId = Uuid::v4();

        // Build an existing assessment with a different id for the same coating+substance pair.
        $existingId = Uuid::v4();
        $existingRepo = $this->createMock(AssessmentRepository::class);
        $existingRepo->method('findByCoatingAndSubstance')->willReturn(null);
        $existingSpec = new AssessmentSpecification(
            new UniqueCoatingSubstanceAssessmentSpecification($existingRepo),
            new AssessmentNotesConsistencyValidator(),
        );
        $existing = new Assessment(
            $existingId, $coatingId, $substanceId,
            Grade::R, null, new StringCollection(),
            $existingSpec, $this->notesRepoWith([]),
        );

        // Now set up a repo that returns the existing assessment for the same pair.
        $conflictRepo = $this->createMock(AssessmentRepository::class);
        $conflictRepo->method('findByCoatingAndSubstance')->willReturn($existing);
        $conflictSpec = new AssessmentSpecification(
            new UniqueCoatingSubstanceAssessmentSpecification($conflictRepo),
            new AssessmentNotesConsistencyValidator(),
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Оценка для этой пары «покрытие — вещество» уже существует.');
        new Assessment(
            Uuid::v4(), $coatingId, $substanceId,
            Grade::NR, null, new StringCollection(),
            $conflictSpec, $this->notesRepoWith([]),
        );
    }
}
