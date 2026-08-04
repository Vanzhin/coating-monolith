<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Infrastructure\Repository\CoatingSystemRepository;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CoatingSystemRepositoryFindByIdsTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private EntityManagerInterface $em;
    private CoatingSystemRepository $repo;

    /** @var list<Uuid> */
    private array $systemIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = new CoatingSystemRepository($this->em);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach ($this->systemIds as $id) {
                $s = $em->find(CoatingSystem::class, $id);
                if (null !== $s) {
                    $em->remove($s);
                }
            }
            $em->flush();
            $this->cleanUpTreatment($em);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_returns_systems_in_provided_order(): void
    {
        $sysA = $this->persistSystem('A');
        $sysB = $this->persistSystem('B');
        $sysC = $this->persistSystem('C');

        $result = $this->repo->findByIds([$sysC->getId(), $sysA->getId(), $sysB->getId()]);

        self::assertCount(3, $result);
        self::assertSame($sysC->getId(), $result[0]->getId());
        self::assertSame($sysA->getId(), $result[1]->getId());
        self::assertSame($sysB->getId(), $result[2]->getId());
    }

    public function test_missing_ids_omitted_silently(): void
    {
        $sysA = $this->persistSystem('A');
        $fakeId = (string) Uuid::v7();

        $result = $this->repo->findByIds([$sysA->getId(), $fakeId]);

        self::assertCount(1, $result);
    }

    public function test_empty_input_returns_empty_array(): void
    {
        self::assertSame([], $this->repo->findByIds([]));
    }

    private function persistSystem(string $suffix): CoatingSystem
    {
        $treatment = $this->createAndPersistTreatment($this->em, $suffix);
        $id = Uuid::v7();
        $system = new CoatingSystem(
            $id,
            'System-'.$suffix,
            'Test coating system '.$suffix,
            Substrate::STEEL_CARBON,
            $treatment,
        );
        $this->repo->save($system);
        $this->systemIds[] = $id;
        $this->em->clear();

        return $system;
    }
}
