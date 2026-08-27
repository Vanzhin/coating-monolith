<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Command;

use App\Coatings\Application\UseCase\Command\CreateSurfaceTreatment\CreateSurfaceTreatmentCommand;
use App\Coatings\Application\UseCase\Command\CreateSurfaceTreatment\CreateSurfaceTreatmentCommandHandler;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CreateSurfaceTreatmentTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private CreateSurfaceTreatmentCommandHandler $handler;
    private EntityManagerInterface $em;

    private ?Uuid $treatmentId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(CreateSurfaceTreatmentCommandHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);

        $this->authenticateAsSystem();
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

    public function test_create_surface_treatment_persists_to_db(): void
    {
        $suffix = bin2hex(random_bytes(3));

        $cmd = new CreateSurfaceTreatmentCommand(
            description: 'Дробеструйная очистка Sa 2½ — тест '.$suffix,
            code: 'Sa2½',
            standardCode: 'ISO 8501-1',
            substrateScope: [Substrate::STEEL_CARBON, Substrate::STEEL_GALVANIZED],
        );

        $result = ($this->handler)($cmd);

        self::assertNotEmpty($result->id);
        $this->treatmentId = Uuid::fromString($result->id);

        $this->em->clear();

        $loaded = $this->em->find(SurfaceTreatment::class, $this->treatmentId);
        self::assertNotNull($loaded, 'SurfaceTreatment должен быть сохранён в БД.');
        self::assertStringContainsString($suffix, $loaded->getDescription());
        self::assertSame('Sa2½', $loaded->getCode());
        self::assertSame('ISO 8501-1', $loaded->getStandardCode());
        self::assertContains(Substrate::STEEL_CARBON, $loaded->getSubstrateScope());
    }

    public function test_create_throws_when_description_empty(): void
    {
        $cmd = new CreateSurfaceTreatmentCommand(
            description: '',
            code: null,
            standardCode: null,
            substrateScope: [Substrate::STEEL_CARBON],
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/пустым/');

        ($this->handler)($cmd);
    }
}
