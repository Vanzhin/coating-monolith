<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Command;

use App\Coatings\Application\UseCase\Command\RemoveSurfaceTreatment\RemoveSurfaceTreatmentCommand;
use App\Coatings\Application\UseCase\Command\RemoveSurfaceTreatment\RemoveSurfaceTreatmentCommandHandler;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Infrastructure\Repository\SurfaceTreatmentRepository;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RemoveSurfaceTreatmentTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private RemoveSurfaceTreatmentCommandHandler $handler;
    private EntityManagerInterface $em;
    private SurfaceTreatmentRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(RemoveSurfaceTreatmentCommandHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = new SurfaceTreatmentRepository($this->em);

        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_remove_deletes_surface_treatment(): void
    {
        $suffix = bin2hex(random_bytes(3));

        $id = Uuid::v7();
        $treatment = new SurfaceTreatment(
            $id,
            'Подготовка для удаления '.$suffix,
            'Sa3',
            'ISO 8501-1',
            [Substrate::STEEL_CARBON],
        );
        $this->repo->save($treatment);

        $this->em->clear();

        $result = ($this->handler)(new RemoveSurfaceTreatmentCommand((string) $id));

        $this->em->clear();
        self::assertNull($this->repo->findById($id), 'SurfaceTreatment должен быть удалён из БД.');
    }

    public function test_remove_throws_when_treatment_not_found(): void
    {
        $fakeId = (string) Uuid::v7();

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/не найдена/');

        ($this->handler)(new RemoveSurfaceTreatmentCommand($fakeId));
    }
}
