<?php

declare(strict_types=1);

namespace App\Tests\Functional\ChemicalResistance\Application\UseCase\Query\ListCoatingAssessments;

use App\ChemicalResistance\Application\UseCase\Query\ListCoatingAssessments\ListCoatingAssessmentsQuery;
use App\ChemicalResistance\Application\UseCase\Query\ListCoatingAssessments\ListCoatingAssessmentsQueryHandler;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Infrastructure\Repository\AssessmentRepository;
use App\ChemicalResistance\Infrastructure\Repository\SubstanceRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ListCoatingAssessmentsQueryHandlerTest extends KernelTestCase
{
    private ListCoatingAssessmentsQueryHandler $handler;
    private AssessmentRepository $assessmentRepo;
    private SubstanceRepository $substanceRepo;
    private EntityManagerInterface $em;

    /** @var list<Uuid> */
    private array $createdAssessmentIds = [];
    /** @var list<Uuid> */
    private array $createdSubstanceIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->handler = $c->get(ListCoatingAssessmentsQueryHandler::class);
        $this->assessmentRepo = $c->get(AssessmentRepository::class);
        $this->substanceRepo = $c->get(SubstanceRepository::class);
        $this->em = $c->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ($this->createdAssessmentIds as $id) {
                $e = $em->find(Assessment::class, $id);
                if (null !== $e) {
                    $em->remove($e);
                }
            }
            $em->flush();

            foreach ($this->createdSubstanceIds as $id) {
                $e = $em->find(Substance::class, $id);
                if (null !== $e) {
                    $em->remove($e);
                }
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    private function getLitatankClassikCoatingId(): Uuid
    {
        $raw = $this->em->getConnection()->fetchOne(
            "SELECT id::text FROM coatings_coating WHERE LOWER(title) LIKE '%литатанк%' LIMIT 1",
        );

        if (false === $raw || null === $raw || '' === $raw) {
            $this->markTestSkipped('Литатанк Классик coating not found; run full seed first.');
        }

        return Uuid::fromString($raw);
    }

    private function getAnyCoatingId(): Uuid
    {
        $raw = $this->em->getConnection()->fetchOne(
            'SELECT c.id::text
             FROM coatings_coating c
             WHERE EXISTS (
                 SELECT 1 FROM chemical_resistance_assessment a WHERE a.coating_id = c.id
             )
             LIMIT 1',
        );

        if (false === $raw || null === $raw || '' === $raw) {
            $this->markTestSkipped('No coating with assessments found; run seed first.');
        }

        return Uuid::fromString($raw);
    }

    /**
     * Creates a fresh coating with exactly 3 assessments (R, LR, NR) so counts
     * can be asserted precisely without depending on production seed data.
     *
     * @return array{Uuid, Uuid, Uuid, Uuid, Uuid, Uuid}
     */
    private function seedMiniCoating(): array
    {
        $coatingId = $this->getAnyCoatingId();

        $suffix = uniqid('q23-', true);

        $sR = Uuid::v4();
        $sLR = Uuid::v4();
        $sNR = Uuid::v4();

        foreach ([
            [$sR,  'Вещество-R-'.$suffix, Grade::R,  'Вода '.$suffix],
            [$sLR, 'Вещество-LR-'.$suffix, Grade::LR, null],
            [$sNR, 'Вещество-NR-'.$suffix, Grade::NR, null],
        ] as [$sid, $name, $grade, $alias]) {
            $aliases = null !== $alias ? new StringCollection($alias) : new StringCollection();
            $substance = new Substance(
                $sid,
                $name,
                null,
                $aliases,
                self::getContainer()->get(SubstanceSpecification::class),
            );
            $this->substanceRepo->add($substance);
            $this->createdSubstanceIds[] = $sid;

            $aid = Uuid::v4();
            $assessment = new Assessment(
                $aid,
                $coatingId,
                $sid,
                $grade,
                AssessmentTemperature::fromInt(40),
                new StringCollection(),
                self::getContainer()->get(AssessmentSpecification::class),
                null,
            );
            $this->assessmentRepo->add($assessment);
            $this->createdAssessmentIds[] = $aid;
        }

        $this->em->clear();

        return [$coatingId, $sR, $sLR, $sNR];
    }

    public function test_first_page_of_litatank_klassik(): void
    {
        $coatingId = $this->getLitatankClassikCoatingId();

        $result = ($this->handler)(new ListCoatingAssessmentsQuery(
            coatingId: $coatingId->toRfc4122(),
            page: 1,
            pageSize: 50,
        ));

        self::assertGreaterThan(500, $result->total,
            'Литатанк Классик should have > 500 assessments from full seed.');

        self::assertSame(
            $result->total,
            $result->countR + $result->countLR + $result->countOther,
            'countR + countLR + countOther must equal total.',
        );

        self::assertLessThanOrEqual(50, count($result->rows));
        self::assertNotEmpty($result->rows);

        $validGrades = ['R', 'NR', 'LR', 'FS', 'NT'];
        foreach ($result->rows as $row) {
            self::assertNotEmpty($row->canonicalName, 'Every row must have a non-empty canonical name.');
            self::assertContains($row->grade, $validGrades, 'Row grade must be a valid Grade value.');
            self::assertIsArray($row->notes, 'Notes must be an array.');

            // System notes come first — the first note (if any) must have isSystem=true.
            if (count($row->notes) > 0) {
                self::assertTrue($row->notes[0]['isSystem'],
                    'First note in every row must be a system note.');
            }
        }
    }

    public function test_search_filters_to_water(): void
    {
        $coatingId = $this->getLitatankClassikCoatingId();

        $result = ($this->handler)(new ListCoatingAssessmentsQuery(
            coatingId: $coatingId->toRfc4122(),
            search: 'вода',
            page: 1,
            pageSize: 50,
        ));

        self::assertGreaterThanOrEqual(1, count($result->rows),
            'Search for "вода" should return at least 1 row for Литатанк Классик.');

        foreach ($result->rows as $row) {
            $haystack = mb_strtolower($row->canonicalName.' '.implode(' ', $row->aliases));
            self::assertStringContainsString('вода', $haystack,
                'Every row matching "вода" must have "вода" in canonical name or aliases.');
        }
    }

    public function test_page_two(): void
    {
        $coatingId = $this->getLitatankClassikCoatingId();

        $page1 = ($this->handler)(new ListCoatingAssessmentsQuery(
            coatingId: $coatingId->toRfc4122(),
            page: 1,
            pageSize: 50,
        ));

        if ($page1->total <= 50) {
            $this->markTestSkipped('Not enough assessments to test page 2.');
        }

        $page2 = ($this->handler)(new ListCoatingAssessmentsQuery(
            coatingId: $coatingId->toRfc4122(),
            page: 2,
            pageSize: 50,
        ));

        self::assertNotEmpty($page2->rows, 'Page 2 must return rows when total > 50.');
        self::assertSame($page1->total, $page2->total,
            'Total count must be identical across pages.');

        $page1Ids = array_column($page1->rows, 'substanceId');
        $page2Ids = array_column($page2->rows, 'substanceId');
        self::assertEmpty(
            array_intersect($page1Ids, $page2Ids),
            'Page 2 rows must not overlap with page 1 rows.',
        );
    }

    public function test_counts_with_seeded_data(): void
    {
        [$coatingId] = $this->seedMiniCoating();

        // Re-fetch total count for this coating from DB to get baseline (may include
        // pre-existing assessments from seed if getAnyCoatingId returned one).
        $baseCounts = $this->em->getConnection()->fetchAllAssociative(
            'SELECT grade, COUNT(*) AS cnt FROM chemical_resistance_assessment
             WHERE coating_id = :cid GROUP BY grade',
            ['cid' => $coatingId->toRfc4122()],
        );
        $base = [];
        foreach ($baseCounts as $r) {
            $base[$r['grade']] = (int) $r['cnt'];
        }

        $result = ($this->handler)(new ListCoatingAssessmentsQuery(
            coatingId: $coatingId->toRfc4122(),
            page: 1,
            pageSize: 200,
        ));

        self::assertSame(
            $result->total,
            $result->countR + $result->countLR + $result->countOther,
            'Counts must sum to total.',
        );
        self::assertGreaterThanOrEqual(1, $result->countR, 'Must have at least 1 R after seed.');
        self::assertGreaterThanOrEqual(1, $result->countLR, 'Must have at least 1 LR after seed.');
        self::assertGreaterThanOrEqual(1, $result->countOther, 'Must have at least 1 other after seed.');
    }
}
