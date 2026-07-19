<?php
declare(strict_types=1);
namespace App\Tests\Functional\ChemicalResistance\Application\UseCase\Query\MatchSubstancesForSearch;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Application\DTO\SubstanceMatchDTO;
use App\ChemicalResistance\Application\UseCase\Query\MatchSubstancesForSearch\MatchSubstancesForSearchQuery;
use App\ChemicalResistance\Application\UseCase\Query\MatchSubstancesForSearch\MatchSubstancesForSearchQueryHandler;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Infrastructure\Repository\AssessmentRepository;
use App\ChemicalResistance\Infrastructure\Repository\SubstanceRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class MatchSubstancesForSearchQueryHandlerTest extends KernelTestCase
{
    private MatchSubstancesForSearchQueryHandler $handler;
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
        $this->handler        = $c->get(MatchSubstancesForSearchQueryHandler::class);
        $this->assessmentRepo = $c->get(AssessmentRepository::class);
        $this->substanceRepo  = $c->get(SubstanceRepository::class);
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
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: ' . $e->getMessage() . "\n");
        }

        parent::tearDown();
    }

    private function getLitatankCoatingId(): Uuid
    {
        $raw = $this->em->getConnection()->fetchOne(
            "SELECT id::text FROM coatings_coating WHERE LOWER(title) LIKE '%литатанк%' LIMIT 1",
        );

        if ($raw === false || $raw === null || $raw === '') {
            $this->markTestSkipped('Литатанк Классик coating not found; run full seed first.');
        }

        return Uuid::fromString($raw);
    }

    private function getAnyCoatingId(): Uuid
    {
        $raw = $this->em->getConnection()->fetchOne(
            "SELECT id::text FROM coatings_coating LIMIT 1",
        );

        if ($raw === false || $raw === null || $raw === '') {
            $this->markTestSkipped('No coating found in DB; run seed first.');
        }

        return Uuid::fromString($raw);
    }

    private function getSecondCoatingId(Uuid $excludeId): Uuid
    {
        $raw = $this->em->getConnection()->fetchOne(
            "SELECT id::text FROM coatings_coating WHERE id != :exclude LIMIT 1",
            ['exclude' => $excludeId->toRfc4122()],
        );

        if ($raw === false || $raw === null || $raw === '') {
            $this->markTestSkipped('Need at least 2 coatings in DB; run seed first.');
        }

        return Uuid::fromString($raw);
    }

    private function createSubstance(string $canonicalName, ?string $cas, ?string $alias): Uuid
    {
        $id     = Uuid::v4();
        $casObj = $cas !== null ? CasNumber::fromString($cas) : null;
        $aliases = $alias !== null ? new StringCollection($alias) : new StringCollection();

        $substance = new Substance(
            $id,
            $canonicalName,
            $casObj,
            $aliases,
            self::getContainer()->get(SubstanceSpecification::class),
        );
        $this->substanceRepo->add($substance);
        $this->createdSubstanceIds[] = $id;

        return $id;
    }

    private function createAssessment(Uuid $coatingId, Uuid $substanceId, Grade $grade): Uuid
    {
        $id = Uuid::v4();
        $assessment = new Assessment(
            $id,
            $coatingId,
            $substanceId,
            $grade,
            AssessmentTemperature::fromInt(20),
            new StringCollection(),
            self::getContainer()->get(AssessmentSpecification::class),
            null,
        );
        $this->assessmentRepo->add($assessment);
        $this->createdAssessmentIds[] = $id;

        return $id;
    }

    public function testMatchesByCanonicalName(): void
    {
        $coatingId = $this->getLitatankCoatingId();

        // Find "Вода" substance for Litatank (seeded) — look up any R/LR assessment
        $row = $this->em->getConnection()->fetchOne(
            "SELECT sub.id::text
             FROM chemical_resistance_assessment a
             JOIN chemical_resistance_substance sub ON sub.id = a.substance_id
             WHERE a.coating_id = :cid
               AND chemical_resistance_is_suitable_grade(a.grade)
               AND LOWER(sub.canonical_name) = 'вода'
             LIMIT 1",
            ['cid' => $coatingId->toRfc4122()],
        );

        if ($row === false || $row === null || $row === '') {
            $this->markTestSkipped('No suitable "Вода" assessment for Литатанк Классик in seed.');
        }

        $result = ($this->handler)(new MatchSubstancesForSearchQuery(
            coatingIds:  [$coatingId->toRfc4122()],
            searchWords: ['вода'],
        ));

        self::assertArrayHasKey($coatingId->toRfc4122(), $result,
            'Coating must appear in result when a suitable "Вода" assessment exists.');

        $matches = $result[$coatingId->toRfc4122()];
        $found   = false;
        foreach ($matches as $dto) {
            self::assertInstanceOf(SubstanceMatchDTO::class, $dto);
            if (mb_strtolower($dto->canonicalName) === 'вода' && $dto->matchedVia === 'canonical') {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Expected a SubstanceMatchDTO for "Вода" with matchedVia=canonical.');
    }

    public function testMatchesByAlias(): void
    {
        $suffix     = uniqid('t25alias-', true);
        $coatingId  = $this->getAnyCoatingId();
        $subId      = $this->createSubstance('Вещество-' . $suffix, null, 'water-' . $suffix);
        $this->createAssessment($coatingId, $subId, Grade::R);
        $this->em->clear();

        $result = ($this->handler)(new MatchSubstancesForSearchQuery(
            coatingIds:  [$coatingId->toRfc4122()],
            searchWords: ['water-' . $suffix],
        ));

        self::assertArrayHasKey($coatingId->toRfc4122(), $result,
            'Coating must appear in result when substance alias matches the search word.');

        $found = false;
        foreach ($result[$coatingId->toRfc4122()] as $dto) {
            if ($dto->substanceId === $subId->toRfc4122() && $dto->matchedVia === 'alias') {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Expected SubstanceMatchDTO with matchedVia=alias.');
    }

    public function testMatchesByCas(): void
    {
        // Use water CAS from seed data if available
        $row = $this->em->getConnection()->fetchOne(
            "SELECT a.coating_id::text
             FROM chemical_resistance_assessment a
             JOIN chemical_resistance_substance sub ON sub.id = a.substance_id
             WHERE sub.cas = '7732-18-5'
               AND chemical_resistance_is_suitable_grade(a.grade)
             LIMIT 1",
        );

        if ($row !== false && $row !== null && $row !== '') {
            $coatingId = $row;
        } else {
            // Create own substance with CAS
            $suffix    = uniqid('t25cas-', true);
            $coatingUuid = $this->getAnyCoatingId();
            $coatingId   = $coatingUuid->toRfc4122();
            $subId       = $this->createSubstance('ВодаCAS-' . $suffix, '7732-18-5', null);
            $this->createAssessment($coatingUuid, $subId, Grade::LR);
            $this->em->clear();
        }

        $result = ($this->handler)(new MatchSubstancesForSearchQuery(
            coatingIds:  [$coatingId],
            searchWords: ['7732-18-5'],
        ));

        self::assertArrayHasKey($coatingId, $result,
            'Coating must appear in result when substance CAS matches the search word.');

        $found = false;
        foreach ($result[$coatingId] as $dto) {
            if ($dto->matchedVia === 'cas') {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Expected SubstanceMatchDTO with matchedVia=cas.');
    }

    public function testEmptyInputsReturnsEmpty(): void
    {
        $coatingId = $this->getAnyCoatingId()->toRfc4122();

        self::assertSame([], ($this->handler)(new MatchSubstancesForSearchQuery(
            coatingIds:  [],
            searchWords: ['вода'],
        )), 'Empty coatingIds must return [].');

        self::assertSame([], ($this->handler)(new MatchSubstancesForSearchQuery(
            coatingIds:  [$coatingId],
            searchWords: [],
        )), 'Empty searchWords must return [].');

        self::assertSame([], ($this->handler)(new MatchSubstancesForSearchQuery(
            coatingIds:  [],
            searchWords: [],
        )), 'Both empty must return [].');
    }

    public function testUnsuitableGradeExcluded(): void
    {
        $suffix      = uniqid('t25grade-', true);
        $coatingR    = $this->getAnyCoatingId();
        $coatingNR   = $this->getSecondCoatingId($coatingR);

        // Same substance linked to one coating as R, another as NR
        $subId = $this->createSubstance('ВеществоGrade-' . $suffix, null, null);
        $this->createAssessment($coatingR,  $subId, Grade::R);
        $this->createAssessment($coatingNR, $subId, Grade::NR);
        $this->em->clear();

        $result = ($this->handler)(new MatchSubstancesForSearchQuery(
            coatingIds:  [$coatingR->toRfc4122(), $coatingNR->toRfc4122()],
            searchWords: ['ВеществоGrade-' . $suffix],
        ));

        self::assertArrayHasKey($coatingR->toRfc4122(), $result,
            'Coating with R grade must be in result.');

        self::assertArrayNotHasKey($coatingNR->toRfc4122(), $result,
            'Coating with NR grade must NOT be in result (unsuitable grade).');
    }

    public function testMultipleWordsAcrossCoatings(): void
    {
        $suffix     = uniqid('t25multi-', true);
        $coatingA   = $this->getAnyCoatingId();
        $coatingB   = $this->getSecondCoatingId($coatingA);

        $subA = $this->createSubstance('ВеществоА-' . $suffix, null, null);
        $subB = $this->createSubstance('ВеществоБ-' . $suffix, null, null);

        $this->createAssessment($coatingA, $subA, Grade::R);
        $this->createAssessment($coatingB, $subB, Grade::LR);
        $this->em->clear();

        $result = ($this->handler)(new MatchSubstancesForSearchQuery(
            coatingIds:  [$coatingA->toRfc4122(), $coatingB->toRfc4122()],
            searchWords: ['ВеществоА-' . $suffix, 'ВеществоБ-' . $suffix],
        ));

        self::assertArrayHasKey($coatingA->toRfc4122(), $result,
            'Coating A must appear with a match for substance A.');
        self::assertArrayHasKey($coatingB->toRfc4122(), $result,
            'Coating B must appear with a match for substance B.');

        $foundA = false;
        foreach ($result[$coatingA->toRfc4122()] as $dto) {
            if ($dto->substanceId === $subA->toRfc4122()) {
                $foundA = true;
                break;
            }
        }
        self::assertTrue($foundA, 'Substance A must be listed under coating A.');

        $foundB = false;
        foreach ($result[$coatingB->toRfc4122()] as $dto) {
            if ($dto->substanceId === $subB->toRfc4122()) {
                $foundB = true;
                break;
            }
        }
        self::assertTrue($foundB, 'Substance B must be listed under coating B.');
    }
}
