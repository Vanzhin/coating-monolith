<?php
declare(strict_types=1);
namespace App\Tests\Functional\ChemicalResistance\Application\UseCase\Command\Assessment;

use App\ChemicalResistance\Application\UseCase\Command\Assessment\CreateAssessment\CreateAssessmentCommand;
use App\ChemicalResistance\Application\UseCase\Command\Assessment\CreateAssessment\CreateAssessmentCommandHandler;
use App\ChemicalResistance\Application\UseCase\Command\Assessment\DeleteAssessment\DeleteAssessmentCommand;
use App\ChemicalResistance\Application\UseCase\Command\Assessment\DeleteAssessment\DeleteAssessmentCommandHandler;
use App\ChemicalResistance\Application\UseCase\Command\Assessment\UpdateAssessment\UpdateAssessmentCommand;
use App\ChemicalResistance\Application\UseCase\Command\Assessment\UpdateAssessment\UpdateAssessmentCommandHandler;
use App\ChemicalResistance\Application\UseCase\Command\Note\CreateNote\CreateNoteCommand;
use App\ChemicalResistance\Application\UseCase\Command\Note\CreateNote\CreateNoteCommandHandler;
use App\ChemicalResistance\Application\UseCase\Command\Note\DeleteNote\DeleteNoteCommand;
use App\ChemicalResistance\Application\UseCase\Command\Note\DeleteNote\DeleteNoteCommandHandler;
use App\ChemicalResistance\Application\UseCase\Command\Substance\CreateSubstance\CreateSubstanceCommand;
use App\ChemicalResistance\Application\UseCase\Command\Substance\CreateSubstance\CreateSubstanceCommandHandler;
use App\ChemicalResistance\Application\UseCase\Command\Substance\DeleteSubstance\DeleteSubstanceCommand;
use App\ChemicalResistance\Application\UseCase\Command\Substance\DeleteSubstance\DeleteSubstanceCommandHandler;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Infrastructure\Repository\DoctrineAssessmentRepository;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AssessmentCrudHandlersTest extends KernelTestCase
{
    private CreateAssessmentCommandHandler $create;
    private UpdateAssessmentCommandHandler $update;
    private DeleteAssessmentCommandHandler $delete;
    private DoctrineAssessmentRepository $assessmentRepo;
    private EntityManagerInterface $em;

    /** @var list<Uuid> */
    private array $createdAssessmentIds = [];
    /** @var list<Uuid> */
    private array $createdSubstanceIds = [];
    /** @var list<Uuid> */
    private array $createdNoteIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->create         = $c->get(CreateAssessmentCommandHandler::class);
        $this->update         = $c->get(UpdateAssessmentCommandHandler::class);
        $this->delete         = $c->get(DeleteAssessmentCommandHandler::class);
        $this->assessmentRepo = $c->get(DoctrineAssessmentRepository::class);
        $this->em             = $c->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ($this->createdAssessmentIds as $id) {
                $e = $em->find(Assessment::class, $id);
                if ($e !== null) {
                    $em->remove($e);
                }
            }
            $em->flush();

            foreach ($this->createdSubstanceIds as $id) {
                $e = $em->find(Substance::class, $id);
                if ($e !== null) {
                    $em->remove($e);
                }
            }
            foreach ($this->createdNoteIds as $id) {
                $e = $em->find(Note::class, $id);
                if ($e !== null) {
                    $em->remove($e);
                }
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: ' . $e->getMessage() . "\n");
        }

        parent::tearDown();
    }

    private function getCoatingId(): Uuid
    {
        $raw = $this->em
            ->getConnection()
            ->fetchOne('SELECT id::text FROM coatings_coating LIMIT 1');

        if ($raw === false || $raw === null || $raw === '') {
            $this->markTestSkipped('No coatings in database; seed a coating first.');
        }

        return Uuid::fromString($raw);
    }

    private function createSubstance(string $suffix): string
    {
        $c = static::getContainer();
        $handler = $c->get(CreateSubstanceCommandHandler::class);
        $id = ($handler)(new CreateSubstanceCommand('Вещество-' . $suffix, null, []));
        $this->createdSubstanceIds[] = Uuid::fromString($id);
        return $id;
    }

    private function createNote(string $suffix): string
    {
        $c = static::getContainer();
        $handler = $c->get(CreateNoteCommandHandler::class);
        $id = ($handler)(new CreateNoteCommand('Примечание-' . $suffix, 'Описание-' . $suffix));
        $this->createdNoteIds[] = Uuid::fromString($id);
        return $id;
    }

    public function testHappyPath(): void
    {
        $coatingId   = $this->getCoatingId();
        $suffix      = uniqid('asmt-happy-', true);
        $substanceId = $this->createSubstance($suffix);
        $noteId      = $this->createNote($suffix);

        // Create
        $id = ($this->create)(new CreateAssessmentCommand(
            $coatingId->toRfc4122(),
            $substanceId,
            'R',
            20,
            [$noteId],
        ));
        $uuid = Uuid::fromString($id);
        $this->createdAssessmentIds[] = $uuid;

        $this->em->clear();
        $loaded = $this->assessmentRepo->find($uuid);
        self::assertNotNull($loaded);
        self::assertSame('R', $loaded->getGrade()->value);
        self::assertSame(20, $loaded->getMaxTemperature()->celsius);
        self::assertSame([$noteId], $loaded->getNoteIds()->getList());

        // Update: change grade, maxTemp to null (becomes default 40), remove notes
        ($this->update)(new UpdateAssessmentCommand($id, 'NR', null, []));

        $this->em->clear();
        $updated = $this->assessmentRepo->find($uuid);
        self::assertNotNull($updated);
        self::assertSame('NR', $updated->getGrade()->value);
        self::assertSame(40, $updated->getMaxTemperature()->celsius);
        self::assertSame([], $updated->getNoteIds()->getList());

        // Delete
        ($this->delete)(new DeleteAssessmentCommand($id));
        $this->em->clear();
        self::assertNull($this->assessmentRepo->find($uuid));

        // Remove from cleanup list — already deleted
        $this->createdAssessmentIds = [];
    }

    public function testDuplicatePairThrows(): void
    {
        $coatingId   = $this->getCoatingId();
        $suffix      = uniqid('asmt-dup-', true);
        $substanceId = $this->createSubstance($suffix);

        $id = ($this->create)(new CreateAssessmentCommand(
            $coatingId->toRfc4122(),
            $substanceId,
            'LR',
            null,
            [],
        ));
        $this->createdAssessmentIds[] = Uuid::fromString($id);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/уже существует/');

        ($this->create)(new CreateAssessmentCommand(
            $coatingId->toRfc4122(),
            $substanceId,
            'NR',
            null,
            [],
        ));
    }

    public function testMissingNoteIdThrows(): void
    {
        $coatingId   = $this->getCoatingId();
        $suffix      = uniqid('asmt-note-', true);
        $substanceId = $this->createSubstance($suffix);

        $nonExistentNoteId = Uuid::v4()->toRfc4122();

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/примечаний/');

        ($this->create)(new CreateAssessmentCommand(
            $coatingId->toRfc4122(),
            $substanceId,
            'R',
            null,
            [$nonExistentNoteId],
        ));
    }
}
