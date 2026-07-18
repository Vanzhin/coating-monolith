<?php
declare(strict_types=1);
namespace App\Tests\Functional\ChemicalResistance\Application\Service;

use App\ChemicalResistance\Application\Service\ChemicalResistanceImporter;
use App\ChemicalResistance\Application\Service\ImportOptions;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Infrastructure\Docx\DocxParseResult;
use App\ChemicalResistance\Infrastructure\Docx\ParsedNote;
use App\ChemicalResistance\Infrastructure\Docx\ParsedRow;
use App\ChemicalResistance\Infrastructure\Repository\DoctrineAssessmentRepository;
use App\ChemicalResistance\Infrastructure\Repository\DoctrineNoteRepository;
use App\ChemicalResistance\Infrastructure\Repository\DoctrineSubstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ChemicalResistanceImporterTest extends KernelTestCase
{
    private ChemicalResistanceImporter $importer;
    private DoctrineAssessmentRepository $assessmentRepo;
    private DoctrineNoteRepository $noteRepo;
    private DoctrineSubstanceRepository $substanceRepo;
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
        $this->importer      = $c->get(ChemicalResistanceImporter::class);
        $this->assessmentRepo = $c->get(DoctrineAssessmentRepository::class);
        $this->noteRepo       = $c->get(DoctrineNoteRepository::class);
        $this->substanceRepo  = $c->get(DoctrineSubstanceRepository::class);
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

    public function testImportFixtureCreatesEverything(): void
    {
        $coatingId = $this->getCoatingId();
        $suffix    = uniqid('importer-', true);

        $parsed = new DocxParseResult(
            rows: [
                new ParsedRow('Бензол-' . $suffix, 'R, 60°C, Прим. 1'),
                new ParsedRow('Ацетон-' . $suffix, 'NR'),
            ],
            notes: [
                new ParsedNote('Прим. 1', 'Примечание 1-' . $suffix, 'Описание примечания 1'),
            ],
        );

        $report = $this->importer->import($parsed, $coatingId, new ImportOptions());

        // Count assertions.
        self::assertSame(1, $report->notesCreated, 'One note should be created');
        self::assertSame(2, $report->substancesCreated, 'Two new substances');
        self::assertSame(0, $report->substancesReused, 'No reused substances');
        self::assertSame(2, $report->assessmentsCreated, 'Two new assessments');
        self::assertSame(0, $report->assessmentsUpdated);
        self::assertCount(0, $report->conflicts);
        self::assertCount(0, $report->warnings);

        // Verify DB state: find the substances by name.
        $this->em->clear();

        $benzol = $this->substanceRepo->findByCanonicalNameKey(
            \App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer::normalize('Бензол-' . $suffix)
        );
        self::assertNotNull($benzol, 'Benzol substance should exist');
        $this->createdSubstanceIds[] = $benzol->id;

        $aceton = $this->substanceRepo->findByCanonicalNameKey(
            \App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer::normalize('Ацетон-' . $suffix)
        );
        self::assertNotNull($aceton, 'Aceton substance should exist');
        $this->createdSubstanceIds[] = $aceton->id;

        // Verify assessments.
        $benzolAssessment = $this->assessmentRepo->findByCoatingAndSubstance($coatingId, $benzol->id);
        self::assertNotNull($benzolAssessment, 'Assessment for Benzol should exist');
        self::assertSame('R', $benzolAssessment->getGrade()->value);
        self::assertSame(60, $benzolAssessment->getMaxTemperature()->celsius);
        self::assertCount(1, $benzolAssessment->getNoteIds()->getList(), 'Benzol assessment should reference 1 note');
        $this->createdAssessmentIds[] = $benzolAssessment->id;

        $acetonAssessment = $this->assessmentRepo->findByCoatingAndSubstance($coatingId, $aceton->id);
        self::assertNotNull($acetonAssessment, 'Assessment for Aceton should exist');
        self::assertSame('NR', $acetonAssessment->getGrade()->value);
        self::assertSame(40, $acetonAssessment->getMaxTemperature()->celsius, 'Default maxTemp is 40');
        $this->createdAssessmentIds[] = $acetonAssessment->id;

        // Verify note was saved with correct title.
        $noteId = Uuid::fromString($benzolAssessment->getNoteIds()->getList()[0]);
        $this->createdNoteIds[] = $noteId;
        $note = $this->noteRepo->find($noteId);
        self::assertNotNull($note, 'Note should be saved');
        self::assertSame('Примечание 1-' . $suffix, $note->getTitle());
    }

    public function testDryRunWritesNothing(): void
    {
        $coatingId = $this->getCoatingId();
        $suffix    = uniqid('importer-dryrun-', true);

        $parsed = new DocxParseResult(
            rows: [
                new ParsedRow('Гексан-' . $suffix, 'R, 60°C'),
            ],
            notes: [
                new ParsedNote('Прим. 1', 'Примечание-dryrun-' . $suffix, 'Описание'),
            ],
        );

        $report = $this->importer->import($parsed, $coatingId, new ImportOptions(dryRun: true));

        // Report should reflect what would have been imported.
        self::assertSame(1, $report->notesCreated, 'Dry-run: note counted but not saved');
        self::assertSame(1, $report->substancesCreated, 'Dry-run: substance counted but not saved');
        self::assertSame(1, $report->assessmentsCreated, 'Dry-run: assessment counted but not saved');
        self::assertCount(0, $report->conflicts);
        self::assertCount(0, $report->warnings);

        // DB must be clean — nothing actually written.
        $this->em->clear();

        $sub = $this->substanceRepo->findByCanonicalNameKey(
            \App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer::normalize('Гексан-' . $suffix)
        );
        self::assertNull($sub, 'Dry-run must not persist a new substance');

        if ($sub !== null) {
            $assessment = $this->assessmentRepo->findByCoatingAndSubstance($coatingId, $sub->id);
            self::assertNull($assessment, 'Dry-run must not persist a new assessment');
        }
    }

    public function testReimportIsIdempotent(): void
    {
        $coatingId = $this->getCoatingId();
        $suffix    = uniqid('importer-idempotent-', true);

        $parsed = new DocxParseResult(
            rows: [
                new ParsedRow('Толуол-' . $suffix, 'LR'),
                new ParsedRow('Ксилол-' . $suffix, 'R'),
            ],
            notes: [],
        );

        // First import.
        $first = $this->importer->import($parsed, $coatingId, new ImportOptions());
        self::assertSame(2, $first->substancesCreated);
        self::assertSame(2, $first->assessmentsCreated);
        self::assertCount(0, $first->conflicts);

        // Collect created IDs for teardown.
        $this->em->clear();
        foreach (['Толуол-' . $suffix, 'Ксилол-' . $suffix] as $name) {
            $sub = $this->substanceRepo->findByCanonicalNameKey(
                \App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer::normalize($name)
            );
            if ($sub !== null) {
                $this->createdSubstanceIds[] = $sub->id;
                $a = $this->assessmentRepo->findByCoatingAndSubstance($coatingId, $sub->id);
                if ($a !== null) {
                    $this->createdAssessmentIds[] = $a->id;
                }
            }
        }

        // Second import without overwrite — all assessments conflict, substances reused.
        $second = $this->importer->import($parsed, $coatingId, new ImportOptions());
        self::assertSame(0, $second->substancesCreated, 'No new substances on re-import');
        self::assertSame(2, $second->substancesReused, 'Both substances reused');
        self::assertSame(0, $second->assessmentsCreated, 'No new assessments');
        self::assertSame(0, $second->assessmentsUpdated, 'No updates without overwrite');
        self::assertCount(2, $second->conflicts, 'Two conflict entries — one per row');
    }
}
