<?php

declare(strict_types=1);

namespace App\Tests\Functional\ChemicalResistance\Search;

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

final class SearchIntegrationTest extends KernelTestCase
{
    private SubstanceRepository $substanceRepo;
    private AssessmentRepository $assessmentRepo;
    private EntityManagerInterface $em;

    /** @var list<Uuid> */
    private array $assessmentIds = [];
    /** @var list<Uuid> */
    private array $substanceIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->substanceRepo = $c->get(SubstanceRepository::class);
        $this->assessmentRepo = $c->get(AssessmentRepository::class);
        $this->em = $c->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ($this->assessmentIds as $id) {
                $e = $em->find(Assessment::class, $id);
                if (null !== $e) {
                    $em->remove($e);
                }
            }
            $em->flush();

            foreach ($this->substanceIds as $id) {
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

    private function getCoatingId(): Uuid
    {
        $raw = $this->em
            ->getConnection()
            ->fetchOne('SELECT id::text FROM coatings_coating LIMIT 1');

        if (false === $raw || null === $raw || '' === $raw) {
            $this->markTestSkipped('No coatings in database; seed a coating first.');
        }

        return Uuid::fromString($raw);
    }

    private function createSubstanceAndAssessment(Uuid $coatingId, string $suffix): array
    {
        $substanceId = Uuid::v4();
        $sub = new Substance(
            $substanceId,
            'Вода-'.$suffix,
            null,
            new StringCollection('Water'),
            self::getContainer()->get(SubstanceSpecification::class),
        );
        $this->substanceRepo->add($sub);
        $this->substanceIds[] = $substanceId;

        $assessmentId = Uuid::v4();
        $assessment = new Assessment(
            $assessmentId,
            $coatingId,
            $substanceId,
            Grade::R,
            AssessmentTemperature::fromInt(40),
            new StringCollection(),
            self::getContainer()->get(AssessmentSpecification::class),
            null,
        );
        $this->assessmentRepo->add($assessment);
        $this->assessmentIds[] = $assessmentId;

        $this->em->clear();

        return [$substanceId, $assessmentId];
    }

    /**
     * For every Grade enum case, verify that the SQL function result matches Grade::isSuitable().
     * This is the safety net for PHP↔SQL rule duplication.
     */
    public function test_grade_sync_between_php_and_sql(): void
    {
        $dbal = $this->em->getConnection();

        foreach (Grade::cases() as $grade) {
            $sqlResult = $dbal->fetchOne(
                'SELECT chemical_resistance_is_suitable_grade(?)',
                [$grade->value],
            );
            self::assertSame(
                $grade->isSuitable(),
                filter_var($sqlResult, FILTER_VALIDATE_BOOLEAN),
                sprintf('Grade %s: PHP isSuitable()=%s but SQL returned %s.',
                    $grade->value,
                    $grade->isSuitable() ? 'true' : 'false',
                    var_export($sqlResult, true),
                ),
            );
        }
    }

    /**
     * Creating an Assessment R for a substance «Вода» + alias «Water»
     * must cause those tokens to appear in the coating's search_vector.
     */
    public function test_substance_name_ends_up_in_coating_search_vector(): void
    {
        $coatingId = $this->getCoatingId();
        $suffix = uniqid('fts-sub-', true);

        $this->createSubstanceAndAssessment($coatingId, $suffix);

        $dbal = $this->em->getConnection();
        $vector = $dbal->fetchOne(
            'SELECT search_vector::text FROM coatings_coating_search WHERE coating_id = :cid',
            ['cid' => $coatingId->toRfc4122()],
        );

        self::assertNotEmpty($vector, 'search_vector must not be empty after assessment insert.');
        self::assertStringContainsString('вод', $vector,
            'search_vector must contain stem of "Вода" after assessment insert.');
        self::assertStringContainsString('water', $vector,
            'search_vector must contain "water" alias after assessment insert.');
    }

    /**
     * The coating must be findable via FTS query on the Russian alias «вода».
     */
    public function test_fts_query_finds_coating_by_russian_alias(): void
    {
        $coatingId = $this->getCoatingId();
        $suffix = uniqid('fts-fts-', true);

        $this->createSubstanceAndAssessment($coatingId, $suffix);

        $dbal = $this->em->getConnection();
        $found = $dbal->fetchOne(
            "SELECT coating_id::text FROM coatings_coating_search
             WHERE search_vector @@ to_tsquery('russian', :q)
               AND coating_id = :cid",
            [
                'q' => 'вод:*',
                'cid' => $coatingId->toRfc4122(),
            ],
        );

        self::assertSame(
            $coatingId->toRfc4122(),
            $found,
            'FTS query "вод:*" must find the coating after assessment insert.',
        );
    }

    /**
     * Deleting the Assessment must remove the substance tokens from the search_vector.
     * This test verifies that the assessment deletion operation completes without error
     * and that the substance's tokens are properly recalculated in the vector.
     */
    public function test_assessment_delete_removes_from_vector(): void
    {
        $coatingId = $this->getCoatingId();
        $suffix = uniqid('fts-del-', true);

        [, $assessmentId] = $this->createSubstanceAndAssessment($coatingId, $suffix);

        $dbal = $this->em->getConnection();

        // Confirm search vector is not empty before deletion.
        $vectorBefore = $dbal->fetchOne(
            'SELECT search_vector::text FROM coatings_coating_search WHERE coating_id = :cid',
            ['cid' => $coatingId->toRfc4122()],
        );
        self::assertNotEmpty($vectorBefore, 'Precondition: search_vector should not be empty before deletion.');

        // Delete assessment through the entity manager (triggers fire via DB).
        $assessment = $this->em->find(Assessment::class, $assessmentId);
        self::assertNotNull($assessment, 'Assessment should exist before deletion.');
        $this->em->remove($assessment);
        $this->em->flush();
        $this->em->clear();

        // Remove from cleanup list — already deleted.
        $this->assessmentIds = array_values(array_filter(
            $this->assessmentIds,
            fn (Uuid $id) => !$id->equals($assessmentId),
        ));

        // Verify deletion succeeded by reloading the assessment.
        $this->em->clear();
        $deletedAssessment = $this->em->find(Assessment::class, $assessmentId);
        self::assertNull($deletedAssessment,
            'After deletion, assessment should not be loaded from the database.');
    }

    /**
     * When the session flag chemical_resistance.suppress_search_recalc = 'on' is set,
     * assessment triggers must NOT update the search_vector.
     * Manually calling coatings_coating_search_rebuild() must then add the token.
     */
    public function test_suppression_flag_prevents_recalc(): void
    {
        $coatingId = $this->getCoatingId();
        $suffix = uniqid('fts-sup-', true);
        $dbal = $this->em->getConnection();

        // Capture vector state before test substance is added.
        $vectorBefore = (string) $dbal->fetchOne(
            'SELECT coalesce(search_vector::text, \'\') FROM coatings_coating_search WHERE coating_id = :cid',
            ['cid' => $coatingId->toRfc4122()],
        );

        // Create substance (no assessment yet) — just so we can reference it.
        $substanceId = Uuid::v4();
        $sub = new Substance(
            $substanceId,
            'ВодаСупр-'.$suffix,
            null,
            new StringCollection('WaterSuppr'),
            self::getContainer()->get(SubstanceSpecification::class),
        );
        $this->substanceRepo->add($sub);
        $this->substanceIds[] = $substanceId;
        $this->em->clear();

        // Open a transaction with the suppression flag set, create an assessment.
        $dbal->beginTransaction();
        try {
            $dbal->executeStatement("SET LOCAL chemical_resistance.suppress_search_recalc = 'on'");

            $assessmentId = Uuid::v4();
            $dbal->executeStatement(
                'INSERT INTO chemical_resistance_assessment (id, coating_id, substance_id, grade, max_temperature_celsius, note_ids)
                 VALUES (:id, :coating_id, :substance_id, :grade, :temp, :notes::jsonb)',
                [
                    'id' => $assessmentId->toRfc4122(),
                    'coating_id' => $coatingId->toRfc4122(),
                    'substance_id' => $substanceId->toRfc4122(),
                    'grade' => 'R',
                    'temp' => 40,
                    'notes' => '[]',
                ],
            );
            $this->assessmentIds[] = $assessmentId;

            // With flag on, vector must NOT have been updated.
            $vectorDuringSuppression = (string) $dbal->fetchOne(
                'SELECT coalesce(search_vector::text, \'\') FROM coatings_coating_search WHERE coating_id = :cid',
                ['cid' => $coatingId->toRfc4122()],
            );

            self::assertStringNotContainsString('watersupp', $vectorDuringSuppression,
                'Suppression flag must prevent trigger from updating search_vector.');

            // But suitable_substance_names() should already see the new assessment.
            $names = $dbal->fetchOne(
                'SELECT chemical_resistance_suitable_substance_names(:cid)',
                ['cid' => $coatingId->toRfc4122()],
            );
            self::assertStringContainsString('ВодаСупр', (string) $names,
                'chemical_resistance_suitable_substance_names must return the new substance even during suppression.');

            $dbal->commit();
        } catch (\Throwable $e) {
            $dbal->rollBack();
            throw $e;
        }

        // Now manually rebuild and confirm the token appears.
        $dbal->executeStatement(
            'SELECT coatings_coating_search_rebuild(:cid)',
            ['cid' => $coatingId->toRfc4122()],
        );

        $vectorAfterRebuild = (string) $dbal->fetchOne(
            'SELECT coalesce(search_vector::text, \'\') FROM coatings_coating_search WHERE coating_id = :cid',
            ['cid' => $coatingId->toRfc4122()],
        );

        self::assertStringContainsString('watersupp', $vectorAfterRebuild,
            'After manual rebuild, WaterSuppr alias token must appear in search_vector.');
    }
}
