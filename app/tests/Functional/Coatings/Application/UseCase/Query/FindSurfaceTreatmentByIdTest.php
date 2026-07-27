<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Query;

use App\Coatings\Application\UseCase\Query\FindSurfaceTreatmentById\FindSurfaceTreatmentByIdQuery;
use App\Coatings\Application\UseCase\Query\FindSurfaceTreatmentById\FindSurfaceTreatmentByIdQueryHandler;
use App\Coatings\Application\UseCase\Command\CreateSurfaceTreatment\CreateSurfaceTreatmentCommand;
use App\Coatings\Application\UseCase\Command\CreateSurfaceTreatment\CreateSurfaceTreatmentCommandHandler;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class FindSurfaceTreatmentByIdTest extends KernelTestCase
{
    private FindSurfaceTreatmentByIdQueryHandler $queryHandler;
    private CreateSurfaceTreatmentCommandHandler $createHandler;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->queryHandler = $c->get(FindSurfaceTreatmentByIdQueryHandler::class);
        $this->createHandler = $c->get(CreateSurfaceTreatmentCommandHandler::class);
        $this->em = $c->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        parent::tearDown();
    }

    public function test_find_existing_treatment_returns_dto(): void
    {
        $cmd = new CreateSurfaceTreatmentCommand(
            code: 'Sa-2.5',
            standardCode: 'ISO 12944',
            description: 'Surface preparation by blast cleaning',
            substrateScope: [Substrate::STEEL_CARBON, Substrate::STEEL_GALVANIZED],
        );

        $result = ($this->createHandler)($cmd);
        $this->em->clear();

        $dto = ($this->queryHandler)(new FindSurfaceTreatmentByIdQuery($result->id));

        self::assertNotNull($dto);
        self::assertSame($result->id, $dto->id);
        self::assertSame('Sa-2.5', $dto->code);
        self::assertSame('ISO 12944', $dto->standardCode);
        self::assertSame('Surface preparation by blast cleaning', $dto->description);
        self::assertContains('steel_carbon', $dto->substrateScope);
        self::assertContains('steel_galvanized', $dto->substrateScope);
        self::assertNotNull($dto->createdAt);
        self::assertNotNull($dto->updatedAt);
    }

    public function test_find_nonexistent_treatment_returns_null(): void
    {
        $nonexistentId = Uuid::v4()->toRfc4122();

        $result = ($this->queryHandler)(new FindSurfaceTreatmentByIdQuery($nonexistentId));

        self::assertNull($result);
    }
}
