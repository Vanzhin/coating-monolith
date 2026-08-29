<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Query\GetCoatingsBySubstance;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Infrastructure\Repository\AssessmentRepository;
use App\ChemicalResistance\Infrastructure\Repository\SubstanceRepository;
use App\Coatings\Application\UseCase\Query\GetCoatingsBySubstance\GetCoatingsBySubstanceQuery;
use App\Coatings\Application\UseCase\Query\GetCoatingsBySubstance\GetCoatingsBySubstanceQueryHandler;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Мультивыбор веществ (логика AND) + худший вердикт. Сидим две брэнд-новые
 * субстанции и связываем их с двумя существующими покрытиями так, чтобы одно
 * покрытие было стойко к обоим, а второе — только к одному.
 */
final class GetCoatingsBySubstanceQueryHandlerTest extends KernelTestCase
{
    private GetCoatingsBySubstanceQueryHandler $handler;
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
        $this->handler = $c->get(GetCoatingsBySubstanceQueryHandler::class);
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

    /** @return array{string, string} два разных id покрытий из сида */
    private function twoCoatingIds(): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn(
            'SELECT id::text FROM coatings_coating ORDER BY id LIMIT 2',
        );
        if (count($rows) < 2) {
            $this->markTestSkipped('Нужно минимум 2 покрытия в БД; прогони сид.');
        }

        return [$rows[0], $rows[1]];
    }

    private function seedSubstance(string $name): Uuid
    {
        $id = Uuid::v4();
        $this->substanceRepo->add(new Substance(
            $id,
            $name,
            null,
            new StringCollection(),
            self::getContainer()->get(SubstanceSpecification::class),
        ));
        $this->createdSubstanceIds[] = $id;

        return $id;
    }

    private function seedAssessment(string $coatingId, Uuid $substanceId, Grade $grade, int $temp): void
    {
        $aid = Uuid::v4();
        $this->assessmentRepo->add(new Assessment(
            $aid,
            Uuid::fromString($coatingId),
            $substanceId,
            $grade,
            AssessmentTemperature::fromInt($temp),
            new StringCollection(),
            self::getContainer()->get(AssessmentSpecification::class),
        ));
        $this->createdAssessmentIds[] = $aid;
    }

    public function test_and_intersection_with_worst_verdict(): void
    {
        [$coatingX, $coatingY] = $this->twoCoatingIds();
        $suffix = uniqid('bysub-', true);

        $substanceA = $this->seedSubstance('Вещество-A-'.$suffix);
        $substanceB = $this->seedSubstance('Вещество-B-'.$suffix);

        // X стойко к обоим (R к A@40, LR к B@60); Y — только к A.
        $this->seedAssessment($coatingX, $substanceA, Grade::R, 40);
        $this->seedAssessment($coatingX, $substanceB, Grade::LR, 60);
        $this->seedAssessment($coatingY, $substanceA, Grade::R, 40);
        $this->em->clear();

        $result = ($this->handler)(new GetCoatingsBySubstanceQuery(
            new StringCollection($substanceA->toRfc4122(), $substanceB->toRfc4122()),
            false,
            1,
            24,
        ));

        // AND: только X (Y не стойко к B).
        $ids = array_map(static fn ($i) => $i->coating->id, $result->items);
        self::assertContains($coatingX, $ids, 'X стойко к обоим — должно быть в выдаче.');
        self::assertNotContains($coatingY, $ids, 'Y не стойко к B — AND его отсекает.');

        $x = null;
        foreach ($result->items as $item) {
            if ($item->coating->id === $coatingX) {
                $x = $item;
            }
        }
        self::assertNotNull($x);

        // Худший вердикт: среди R и LR — LR; температура — минимальная (40).
        self::assertSame('LR', $x->grade, 'Худший грейд среди {R, LR} = LR.');
        self::assertSame('Ограниченно', $x->gradeLabel);
        self::assertSame(40, $x->maxTemperature, 'Минимальная (ограничивающая) температура.');
        self::assertCount(2, $x->verdicts, 'Разбивка по обоим выбранным веществам.');

        // Выбранные вещества резолвнуты для чипов.
        self::assertCount(2, $result->selectedSubstances);
    }

    public function test_single_substance_returns_resistant_coating(): void
    {
        [$coatingX] = $this->twoCoatingIds();
        $suffix = uniqid('bysub1-', true);

        $substanceA = $this->seedSubstance('Вещество-single-'.$suffix);
        $this->seedAssessment($coatingX, $substanceA, Grade::R, 50);
        $this->em->clear();

        $result = ($this->handler)(new GetCoatingsBySubstanceQuery(
            new StringCollection($substanceA->toRfc4122()),
            false,
            1,
            24,
        ));

        $ids = array_map(static fn ($i) => $i->coating->id, $result->items);
        self::assertContains($coatingX, $ids);

        foreach ($result->items as $item) {
            if ($item->coating->id === $coatingX) {
                self::assertSame('R', $item->grade);
                self::assertSame(50, $item->maxTemperature);
            }
        }
    }
}
