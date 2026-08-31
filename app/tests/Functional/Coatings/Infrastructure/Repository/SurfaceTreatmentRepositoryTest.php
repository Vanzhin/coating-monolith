<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Domain\Repository\SurfaceTreatmentRepositoryInterface;
use App\Coatings\Domain\Repository\SurfaceTreatmentsFilter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SurfaceTreatmentRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SurfaceTreatmentRepositoryInterface $repo;

    /** @var list<Uuid> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = $container->get(SurfaceTreatmentRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach ($this->createdIds as $id) {
                $t = $em->find(SurfaceTreatment::class, $id);
                if (null !== $t) {
                    $em->remove($t);
                }
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    /**
     * @param list<Substrate> $scope
     */
    private function make(
        string $description,
        ?string $code = null,
        ?string $standardCode = null,
        array $scope = [Substrate::STEEL_CARBON],
    ): SurfaceTreatment {
        $id = Uuid::v7();
        $t = new SurfaceTreatment($id, $description, $code, $standardCode, $scope);
        $this->repo->save($t);
        $this->createdIds[] = $id;

        return $t;
    }

    public function test_save_and_find_by_id_round_trip(): void
    {
        $id = Uuid::v7();
        $treatment = new SurfaceTreatment(
            $id,
            'Абразивоструйная очистка до степени Sa 2½.',
            'Sa 2½',
            'ГОСТ Р ИСО 8501-1',
            [Substrate::STEEL_CARBON, Substrate::STEEL_GALVANIZED],
        );
        $this->repo->save($treatment);
        $this->createdIds[] = $id;

        $this->em->clear();

        $loaded = $this->repo->findById($id);

        self::assertNotNull($loaded);
        self::assertSame((string) $id, $loaded->getId());
        self::assertSame('Абразивоструйная очистка до степени Sa 2½.', $loaded->getDescription());
        self::assertSame('Sa 2½', $loaded->getCode());
        self::assertSame('ГОСТ Р ИСО 8501-1', $loaded->getStandardCode());
        self::assertSame(
            [Substrate::STEEL_CARBON, Substrate::STEEL_GALVANIZED],
            $loaded->getSubstrateScope(),
        );
        self::assertInstanceOf(\DateTimeImmutable::class, $loaded->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $loaded->getUpdatedAt());
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        $result = $this->repo->findById(Uuid::v7());
        self::assertNull($result);
    }

    public function test_list_filter_by_substrate(): void
    {
        $suffix = bin2hex(random_bytes(3));

        $carbon = $this->make('Очистка углеродистой-'.$suffix, scope: [Substrate::STEEL_CARBON]);
        $galvanized = $this->make('Очистка оцинкованной-'.$suffix, scope: [Substrate::STEEL_GALVANIZED]);
        $both = $this->make('Очистка обеих-'.$suffix, scope: [Substrate::STEEL_CARBON, Substrate::STEEL_GALVANIZED]);

        $this->em->clear();

        $filter = new SurfaceTreatmentsFilter(substrate: Substrate::STEEL_CARBON);
        $result = $this->repo->list($filter, 100, 0);

        $ids = array_map(fn (SurfaceTreatment $t) => $t->getId(), $result);
        self::assertContains($carbon->getId(), $ids);
        self::assertContains($both->getId(), $ids);
        self::assertNotContains($galvanized->getId(), $ids);
    }

    public function test_list_filter_by_q_code(): void
    {
        $suffix = bin2hex(random_bytes(3));

        $this->make('Описание очистки Алфа-'.$suffix, code: 'Sa-'.$suffix);
        $this->make('Описание очистки Бета-'.$suffix, code: 'St-'.$suffix);

        $this->em->clear();

        $filter = new SurfaceTreatmentsFilter(q: 'Sa-'.$suffix);
        $result = $this->repo->list($filter, 100, 0);

        self::assertCount(1, $result);
        self::assertSame('Sa-'.$suffix, $result[0]->getCode());
    }

    public function test_list_filter_by_q_description(): void
    {
        $suffix = bin2hex(random_bytes(3));

        $this->make('Уникальное-описание-'.$suffix.'-Альфа');
        $this->make('Уникальное-описание-'.$suffix.'-Бета');

        $this->em->clear();

        $filter = new SurfaceTreatmentsFilter(q: 'описание-'.$suffix);
        $result = $this->repo->list($filter, 100, 0);

        self::assertCount(2, $result);
    }

    public function test_count(): void
    {
        $suffix = bin2hex(random_bytes(3));

        $this->make('Для счётчика А-'.$suffix, code: 'CntA-'.$suffix, scope: [Substrate::CONCRETE]);
        $this->make('Для счётчика Б-'.$suffix, code: 'CntB-'.$suffix, scope: [Substrate::CONCRETE]);
        $this->make('Для счётчика В-'.$suffix, code: 'CntC-'.$suffix, scope: [Substrate::STEEL_CARBON]);

        $this->em->clear();

        $concrete = new SurfaceTreatmentsFilter(substrate: Substrate::CONCRETE);
        self::assertGreaterThanOrEqual(2, $this->repo->count($concrete));

        $carbon = new SurfaceTreatmentsFilter(substrate: Substrate::STEEL_CARBON);
        self::assertGreaterThanOrEqual(1, $this->repo->count($carbon));
    }

    public function test_unique_constraint_code_and_standard_code(): void
    {
        // Наличие частичного unique-индекса проверяем запросом к схеме, а НЕ вставкой
        // дубля. Намеренное нарушение ограничения под DAMA закрывает EntityManager и
        // оставляет общую (статическую) транзакцию оборванной — это роняло случайные
        // соседние тесты каскадом (флака CI, зависящая от порядка).
        $exists = (bool) $this->em->getConnection()->fetchOne(
            "SELECT 1 FROM pg_indexes WHERE tablename = 'surface_treatment' AND indexname = 'uniq_surface_treatment_code_std'",
        );

        self::assertTrue($exists, 'Ожидается частичный unique-индекс uniq_surface_treatment_code_std на (code, standard_code).');
    }

    public function test_null_code_and_standard_allows_duplicates(): void
    {
        $suffix = bin2hex(random_bytes(3));

        $this->make('Обмыв водой-'.$suffix.'-1');
        $this->make('Обмыв водой-'.$suffix.'-2');

        $this->em->clear();

        $filter = new SurfaceTreatmentsFilter(q: 'Обмыв водой-'.$suffix);
        $result = $this->repo->list($filter, 100, 0);
        self::assertCount(2, $result);
    }

    public function test_remove(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = Uuid::v7();
        $treatment = new SurfaceTreatment($id, 'Для удаления-'.$suffix, null, null, [Substrate::ALUMINUM]);
        $this->repo->save($treatment);

        $this->em->clear();

        $loaded = $this->repo->findById($id);
        self::assertNotNull($loaded);

        $this->repo->remove($loaded);
        $this->em->clear();

        self::assertNull($this->repo->findById($id));
    }
}
