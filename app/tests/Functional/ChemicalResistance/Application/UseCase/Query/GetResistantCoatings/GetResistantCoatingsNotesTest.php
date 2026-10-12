<?php

declare(strict_types=1);

namespace App\Tests\Functional\ChemicalResistance\Application\UseCase\Query\GetResistantCoatings;

use App\ChemicalResistance\Application\DTO\AssessmentNoteDTO;
use App\ChemicalResistance\Application\UseCase\Query\GetResistantCoatings\GetResistantCoatingsQuery;
use App\ChemicalResistance\Application\UseCase\Query\GetResistantCoatings\GetResistantCoatingsQueryHandler;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Infrastructure\Repository\AssessmentRepository;
use App\ChemicalResistance\Infrastructure\Repository\NoteRepository;
use App\ChemicalResistance\Infrastructure\Repository\SubstanceRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Примечание оценки должно доходить до read-side: ResistantCoatingDTO несёт notes
 * (для значка «есть примечание» и popover на карточках обратного поиска).
 */
final class GetResistantCoatingsNotesTest extends KernelTestCase
{
    private SubstanceRepository $substances;
    private NoteRepository $notes;
    private AssessmentRepository $assessments;
    private GetResistantCoatingsQueryHandler $handler;
    private EntityManagerInterface $em;

    private ?Uuid $substanceId = null;
    private ?Uuid $noteId = null;
    private ?Uuid $assessmentId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->substances = $c->get(SubstanceRepository::class);
        $this->notes = $c->get(NoteRepository::class);
        $this->assessments = $c->get(AssessmentRepository::class);
        $this->handler = $c->get(GetResistantCoatingsQueryHandler::class);
        $this->em = $c->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ([[Assessment::class, $this->assessmentId], [Substance::class, $this->substanceId], [Note::class, $this->noteId]] as [$class, $id]) {
                if (null !== $id && null !== ($e = $em->find($class, $id))) {
                    $em->remove($e);
                }
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_assessment_note_reaches_resistant_coating_dto(): void
    {
        $coatingIdRaw = $this->em->getConnection()->fetchOne('SELECT id::text FROM coatings_coating LIMIT 1');
        if (false === $coatingIdRaw || null === $coatingIdRaw || '' === $coatingIdRaw) {
            $this->markTestSkipped('No coatings in database; seed a coating first.');
        }
        $coatingId = Uuid::fromString($coatingIdRaw);
        $suffix = uniqid('', true);

        $this->substanceId = Uuid::v4();
        $this->substances->add(new Substance(
            $this->substanceId,
            'Кислота-тест-'.$suffix,
            null,
            new StringCollection(),
            static::getContainer()->get(SubstanceSpecification::class),
        ));

        $this->noteId = Uuid::v4();
        $this->notes->add(new Note($this->noteId, 'Прим.-тест-'.$suffix, 'Описание примечания '.$suffix));

        $this->assessmentId = Uuid::v4();
        $this->assessments->add(new Assessment(
            $this->assessmentId,
            $coatingId,
            $this->substanceId,
            Grade::R,
            AssessmentTemperature::fromInt(40),
            new StringCollection($this->noteId->toRfc4122()),
            static::getContainer()->get(AssessmentSpecification::class),
        ));
        $this->em->clear();

        $result = ($this->handler)(new GetResistantCoatingsQuery($this->substanceId->toRfc4122(), false));

        self::assertCount(1, $result);
        $dto = $result[0];
        $userNotes = array_values(array_filter($dto->notes, static fn (AssessmentNoteDTO $n) => !$n->isSystem));
        self::assertCount(1, $userNotes, 'Экспонируем именно несистемное примечание оценки.');
        self::assertSame('Прим.-тест-'.$suffix, $userNotes[0]->title);
        self::assertSame('Описание примечания '.$suffix, $userNotes[0]->description);
    }
}
