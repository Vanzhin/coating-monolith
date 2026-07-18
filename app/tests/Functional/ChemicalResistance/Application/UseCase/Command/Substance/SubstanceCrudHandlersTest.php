<?php
declare(strict_types=1);
namespace App\Tests\Functional\ChemicalResistance\Application\UseCase\Command\Substance;

use App\ChemicalResistance\Application\UseCase\Command\Substance\CreateSubstance\CreateSubstanceCommand;
use App\ChemicalResistance\Application\UseCase\Command\Substance\CreateSubstance\CreateSubstanceCommandHandler;
use App\ChemicalResistance\Application\UseCase\Command\Substance\DeleteSubstance\DeleteSubstanceCommand;
use App\ChemicalResistance\Application\UseCase\Command\Substance\DeleteSubstance\DeleteSubstanceCommandHandler;
use App\ChemicalResistance\Application\UseCase\Command\Substance\UpdateSubstance\UpdateSubstanceCommand;
use App\ChemicalResistance\Application\UseCase\Command\Substance\UpdateSubstance\UpdateSubstanceCommandHandler;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Infrastructure\Repository\DoctrineAssessmentRepository;
use App\ChemicalResistance\Infrastructure\Repository\DoctrineNoteRepository;
use App\ChemicalResistance\Infrastructure\Repository\DoctrineSubstanceRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SubstanceCrudHandlersTest extends KernelTestCase
{
    private CreateSubstanceCommandHandler $create;
    private UpdateSubstanceCommandHandler $update;
    private DeleteSubstanceCommandHandler $delete;
    private DoctrineSubstanceRepository $substances;
    private EntityManagerInterface $em;

    /** @var list<Uuid> */
    private array $createdSubstanceIds = [];
    /** @var list<Uuid> */
    private array $createdAssessmentIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->create    = $c->get(CreateSubstanceCommandHandler::class);
        $this->update    = $c->get(UpdateSubstanceCommandHandler::class);
        $this->delete    = $c->get(DeleteSubstanceCommandHandler::class);
        $this->substances = $c->get(DoctrineSubstanceRepository::class);
        $this->em        = $c->get(EntityManagerInterface::class);
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
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: ' . $e->getMessage() . "\n");
        }

        parent::tearDown();
    }

    public function testCreateSubstance(): void
    {
        $suffix = uniqid('sub-create-', true);
        $id = ($this->create)(new CreateSubstanceCommand(
            'Вещество-' . $suffix,
            '7732-18-5',
            ['alias-a-' . $suffix, 'alias-b-' . $suffix],
        ));
        $uuid = Uuid::fromString($id);
        $this->createdSubstanceIds[] = $uuid;

        $this->em->clear();
        $loaded = $this->substances->find($uuid);
        self::assertNotNull($loaded);
        self::assertSame('Вещество-' . $suffix, $loaded->getCanonicalName());
        self::assertNotNull($loaded->getCas());
        self::assertSame('7732-18-5', $loaded->getCas()->value);
        self::assertCount(2, $loaded->getAliases()->getList());
    }

    public function testUpdateSubstance(): void
    {
        $suffix = uniqid('sub-update-', true);
        $id = ($this->create)(new CreateSubstanceCommand(
            'Вещество-' . $suffix,
            null,
            [],
        ));
        $uuid = Uuid::fromString($id);
        $this->createdSubstanceIds[] = $uuid;

        ($this->update)(new UpdateSubstanceCommand(
            $id,
            'Обновлённое-' . $suffix,
            '7664-93-9',
            ['новый-псевдоним-' . $suffix],
        ));

        $this->em->clear();
        $updated = $this->substances->find($uuid);
        self::assertNotNull($updated);
        self::assertSame('Обновлённое-' . $suffix, $updated->getCanonicalName());
        self::assertNotNull($updated->getCas());
        self::assertSame('7664-93-9', $updated->getCas()->value);
        self::assertCount(1, $updated->getAliases()->getList());
    }

    public function testDeleteSubstance(): void
    {
        $suffix = uniqid('sub-delete-', true);
        $id = ($this->create)(new CreateSubstanceCommand(
            'Вещество-del-' . $suffix,
            null,
            [],
        ));
        $uuid = Uuid::fromString($id);
        $this->createdSubstanceIds[] = $uuid;

        ($this->delete)(new DeleteSubstanceCommand($id));
        $this->em->clear();
        self::assertNull($this->substances->find($uuid));

        // Already deleted — remove from cleanup list
        $this->createdSubstanceIds = [];
    }

    public function testDeleteSubstanceBlockedByAssessments(): void
    {
        $coatingIdRaw = $this->em
            ->getConnection()
            ->fetchOne('SELECT id::text FROM coatings_coating LIMIT 1');

        if ($coatingIdRaw === false || $coatingIdRaw === null || $coatingIdRaw === '') {
            $this->markTestSkipped('No coatings in database; seed a coating first.');
        }

        $coatingId = Uuid::fromString($coatingIdRaw);
        $suffix = uniqid('sub-fk-', true);

        $id = ($this->create)(new CreateSubstanceCommand(
            'ВеществоFK-' . $suffix,
            null,
            [],
        ));
        $substanceUuid = Uuid::fromString($id);
        $this->createdSubstanceIds[] = $substanceUuid;

        $assessments = static::getContainer()->get(DoctrineAssessmentRepository::class);
        $noteRepo = static::getContainer()->get(DoctrineNoteRepository::class);
        $assessmentId = Uuid::v4();
        $this->createdAssessmentIds[] = $assessmentId;
        $assessment = new Assessment(
            $assessmentId,
            $coatingId,
            $substanceUuid,
            Grade::R,
            AssessmentTemperature::fromInt(20),
            new StringCollection(),
            $assessments->makeSpec(),
            $noteRepo,
        );
        $assessments->save($assessment);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/оценках/');
        ($this->delete)(new DeleteSubstanceCommand($id));
    }
}
