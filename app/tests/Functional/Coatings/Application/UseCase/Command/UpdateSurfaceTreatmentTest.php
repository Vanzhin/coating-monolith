<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Command;

use App\Coatings\Application\UseCase\Command\UpdateSurfaceTreatment\UpdateSurfaceTreatmentCommand;
use App\Coatings\Application\UseCase\Command\UpdateSurfaceTreatment\UpdateSurfaceTreatmentCommandHandler;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Infrastructure\Repository\SurfaceTreatmentRepository;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class UpdateSurfaceTreatmentTest extends KernelTestCase
{
    private UpdateSurfaceTreatmentCommandHandler $handler;
    private EntityManagerInterface $em;
    private SurfaceTreatmentRepository $repo;

    private ?Uuid $treatmentId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(UpdateSurfaceTreatmentCommandHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = new SurfaceTreatmentRepository($this->em);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            if (null !== $this->treatmentId) {
                $t = $em->find(SurfaceTreatment::class, $this->treatmentId);
                if (null !== $t) {
                    $em->remove($t);
                    $em->flush();
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_update_surface_treatment_persists_changes(): void
    {
        $suffix = bin2hex(random_bytes(3));

        $this->treatmentId = Uuid::v7();
        $treatment = new SurfaceTreatment(
            $this->treatmentId,
            'До обновления '.$suffix,
            'Sa1',
            null,
            [Substrate::STEEL_CARBON],
        );
        $this->repo->save($treatment);

        $cmd = new UpdateSurfaceTreatmentCommand(
            id: (string) $this->treatmentId,
            description: 'После обновления '.$suffix,
            code: 'Sa2½',
            standardCode: 'ISO 8501-1',
            substrateScope: [Substrate::STEEL_CARBON, Substrate::CONCRETE],
        );

        $result = ($this->handler)($cmd);

        $this->em->clear();

        $loaded = $this->em->find(SurfaceTreatment::class, $this->treatmentId);
        self::assertNotNull($loaded);
        self::assertSame('После обновления '.$suffix, $loaded->getDescription());
        self::assertSame('Sa2½', $loaded->getCode());
        self::assertSame('ISO 8501-1', $loaded->getStandardCode());
        self::assertContains(Substrate::CONCRETE, $loaded->getSubstrateScope());
    }

    public function test_update_throws_when_treatment_not_found(): void
    {
        $fakeId = (string) Uuid::v7();

        $cmd = new UpdateSurfaceTreatmentCommand(
            id: $fakeId,
            description: 'Не важно',
            code: null,
            standardCode: null,
            substrateScope: [Substrate::STEEL_CARBON],
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/не найдена/');

        ($this->handler)($cmd);
    }
}
