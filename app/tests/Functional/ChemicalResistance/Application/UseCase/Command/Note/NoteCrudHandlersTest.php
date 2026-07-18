<?php
declare(strict_types=1);
namespace App\Tests\Functional\ChemicalResistance\Application\UseCase\Command\Note;

use App\ChemicalResistance\Application\UseCase\Command\Note\CreateNote\CreateNoteCommand;
use App\ChemicalResistance\Application\UseCase\Command\Note\CreateNote\CreateNoteCommandHandler;
use App\ChemicalResistance\Application\UseCase\Command\Note\DeleteNote\DeleteNoteCommand;
use App\ChemicalResistance\Application\UseCase\Command\Note\DeleteNote\DeleteNoteCommandHandler;
use App\ChemicalResistance\Application\UseCase\Command\Note\UpdateNote\UpdateNoteCommand;
use App\ChemicalResistance\Application\UseCase\Command\Note\UpdateNote\UpdateNoteCommandHandler;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
use App\ChemicalResistance\Infrastructure\Repository\DoctrineAssessmentRepository;
use App\ChemicalResistance\Infrastructure\Repository\DoctrineNoteRepository;
use App\ChemicalResistance\Infrastructure\Repository\DoctrineSubstanceRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class NoteCrudHandlersTest extends KernelTestCase
{
    private CreateNoteCommandHandler $create;
    private UpdateNoteCommandHandler $update;
    private DeleteNoteCommandHandler $delete;
    private NoteRepository $notes;
    private EntityManagerInterface $em;

    /** @var list<Uuid> */
    private array $createdNoteIds = [];
    /** @var list<Uuid> */
    private array $createdSubstanceIds = [];
    /** @var list<Uuid> */
    private array $createdAssessmentIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->create = $c->get(CreateNoteCommandHandler::class);
        $this->update = $c->get(UpdateNoteCommandHandler::class);
        $this->delete = $c->get(DeleteNoteCommandHandler::class);
        $this->notes  = $c->get(DoctrineNoteRepository::class);
        $this->em     = $c->get(EntityManagerInterface::class);
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

    public function testCreateUpdateDelete(): void
    {
        $id = ($this->create)(new CreateNoteCommand('T1', 'D1'));
        $this->createdNoteIds[] = Uuid::fromString($id);

        $loaded = $this->notes->find(Uuid::fromString($id));
        self::assertNotNull($loaded);
        self::assertSame('T1', $loaded->getTitle());
        self::assertSame('D1', $loaded->getDescription());

        ($this->update)(new UpdateNoteCommand($id, 'T2', 'D2'));
        $this->em->clear();
        $updated = $this->notes->find(Uuid::fromString($id));
        self::assertNotNull($updated);
        self::assertSame('T2', $updated->getTitle());
        self::assertSame('D2', $updated->getDescription());

        ($this->delete)(new DeleteNoteCommand($id));
        $this->em->clear();
        self::assertNull($this->notes->find(Uuid::fromString($id)));

        // Already deleted — remove from cleanup list
        $this->createdNoteIds = [];
    }

    public function testDeleteBlockedWhenReferenced(): void
    {
        $coatingIdRaw = $this->em
            ->getConnection()
            ->fetchOne('SELECT id::text FROM coatings_coating LIMIT 1');

        if ($coatingIdRaw === false || $coatingIdRaw === null || $coatingIdRaw === '') {
            $this->markTestSkipped('No coatings in database; seed a coating first.');
        }

        $coatingId = Uuid::fromString($coatingIdRaw);
        $suffix = uniqid('note-crud-', true);

        // Create note via handler
        $noteId = ($this->create)(new CreateNoteCommand('Block-test-' . $suffix, 'desc'));
        $noteUuid = Uuid::fromString($noteId);
        $this->createdNoteIds[] = $noteUuid;

        // Create substance
        $substances = static::getContainer()->get(DoctrineSubstanceRepository::class);
        $substanceId = Uuid::v4();
        $this->createdSubstanceIds[] = $substanceId;
        $substance = new Substance(
            $substanceId,
            'Вещество-' . $suffix,
            null,
            new StringCollection(),
            $substances->makeSpec(),
        );
        $substances->save($substance);

        // Create assessment referencing the note
        $assessments = static::getContainer()->get(DoctrineAssessmentRepository::class);
        $assessmentId = Uuid::v4();
        $this->createdAssessmentIds[] = $assessmentId;
        $noteRepo = static::getContainer()->get(DoctrineNoteRepository::class);
        $assessment = new Assessment(
            $assessmentId,
            $coatingId,
            $substanceId,
            Grade::R,
            AssessmentTemperature::fromInt(20),
            new StringCollection($noteId),
            $assessments->makeSpec(),
            $noteRepo,
        );
        $assessments->save($assessment);

        // Attempt delete — must be blocked
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/оценках/');
        ($this->delete)(new DeleteNoteCommand($noteId));
    }
}
