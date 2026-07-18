<?php
declare(strict_types=1);
namespace App\Tests\Functional\ChemicalResistance;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Infrastructure\Repository\DoctrineAssessmentRepository;
use App\ChemicalResistance\Infrastructure\Repository\DoctrineNoteRepository;
use App\ChemicalResistance\Infrastructure\Repository\DoctrineSubstanceRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PersistenceRoundTripTest extends KernelTestCase
{
    private DoctrineSubstanceRepository $substances;
    private DoctrineNoteRepository $notes;
    private DoctrineAssessmentRepository $assessments;
    private EntityManagerInterface $em;

    private ?Uuid $substanceId = null;
    private ?Uuid $noteId = null;
    private ?Uuid $assessmentId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->substances = $container->get(DoctrineSubstanceRepository::class);
        $this->notes = $container->get(DoctrineNoteRepository::class);
        $this->assessments = $container->get(DoctrineAssessmentRepository::class);
        $this->em = $container->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            if ($this->assessmentId !== null) {
                $a = $em->find(Assessment::class, $this->assessmentId);
                if ($a !== null) {
                    $em->remove($a);
                }
            }
            if ($this->substanceId !== null) {
                $s = $em->find(Substance::class, $this->substanceId);
                if ($s !== null) {
                    $em->remove($s);
                }
            }
            if ($this->noteId !== null) {
                $n = $em->find(Note::class, $this->noteId);
                if ($n !== null) {
                    $em->remove($n);
                }
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: ' . $e->getMessage() . "\n");
        }

        parent::tearDown();
    }

    public function testSaveAndLoadAll(): void
    {
        // Precondition: a real coating must exist (Assessment FK references coatings_coating).
        $coatingIdRaw = $this->em
            ->getConnection()
            ->fetchOne('SELECT id::text FROM coatings_coating LIMIT 1');

        if ($coatingIdRaw === false || $coatingIdRaw === null || $coatingIdRaw === '') {
            $this->markTestSkipped('No coatings in database; seed a coating first.');
        }

        $coatingId = Uuid::fromString($coatingIdRaw);
        $suffix = uniqid('', true);

        // --- Substance ---
        $this->substanceId = Uuid::v4();
        $sub = new Substance(
            $this->substanceId,
            'Вода-тест-' . $suffix,
            null,
            new StringCollection('Water', 'H2O'),
            $this->substances->makeSpec(),
        );
        $this->substances->save($sub);

        // --- Note ---
        $this->noteId = Uuid::v4();
        $note = new Note($this->noteId, 'Изменение цвета', 'Тест-описание-' . $suffix);
        $this->notes->save($note);

        // --- Assessment ---
        $this->assessmentId = Uuid::v4();
        $assessment = new Assessment(
            $this->assessmentId,
            $coatingId,
            $this->substanceId,
            Grade::R,
            AssessmentTemperature::fromInt(70),
            new StringCollection($note->getId()),
            $this->assessments->makeSpec(),
            $this->notes,
        );
        $this->assessments->save($assessment);

        // Clear the identity map to force real DB reads.
        $this->em->clear();

        // --- Round-trip: Substance ---
        $normalizedKey = \App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer::normalize('Вода-тест-' . $suffix);
        $loadedSub = $this->substances->findByCanonicalNameKey($normalizedKey);
        self::assertNotNull($loadedSub, 'Substance should be loadable by canonicalNameKey.');
        self::assertNull($loadedSub->getCas());
        self::assertSame(['Water', 'H2O'], $loadedSub->getAliases()->getList());

        // --- Round-trip: Note ---
        $loadedNote = $this->notes->find($this->noteId);
        self::assertNotNull($loadedNote, 'Note should be loadable by id.');
        self::assertSame('Изменение цвета', $loadedNote->getTitle());
        self::assertSame('Тест-описание-' . $suffix, $loadedNote->getDescription());

        // --- Round-trip: Assessment ---
        $loadedAssessment = $this->assessments->findByCoatingAndSubstance($coatingId, $this->substanceId);
        self::assertNotNull($loadedAssessment, 'Assessment should be loadable by coating+substance.');
        self::assertSame(Grade::R, $loadedAssessment->getGrade());
        self::assertSame(70, $loadedAssessment->getMaxTemperature()->celsius);
        self::assertSame([$note->getId()], $loadedAssessment->getNoteIds()->getList());
    }
}
