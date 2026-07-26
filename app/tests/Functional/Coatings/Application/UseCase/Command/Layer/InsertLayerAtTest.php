<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Command\Layer;

use App\Coatings\Application\UseCase\Command\InsertLayerAt\InsertLayerAtCommand;
use App\Coatings\Application\UseCase\Command\InsertLayerAt\InsertLayerAtCommandHandler;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InsertLayerAtTest extends KernelTestCase
{
    use CoatingSystemLayerTestFixtureTrait;

    private InsertLayerAtCommandHandler $handler;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(InsertLayerAtCommandHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->setUpFixture($container, $this->em);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture(static::getContainer()->get(EntityManagerInterface::class));
        parent::tearDown();
    }

    public function test_insert_layer_at_position_1_shifts_existing(): void
    {
        $cmd = new InsertLayerAtCommand(
            systemId: (string) $this->systemId,
            position: 1,
            coatingId: (string) $this->coatingId,
            dft: 75,
        );

        $result = ($this->handler)($cmd);

        self::assertNotEmpty($result->layerId);

        $this->em->clear();

        $loaded = $this->em->find(CoatingSystem::class, $this->systemId);
        self::assertNotNull($loaded);
        self::assertSame(2, $loaded->layerCount());

        $layers = array_values($loaded->getLayers()->toArray());
        self::assertSame(1, $layers[0]->getPosition());
        self::assertSame(75, $layers[0]->getDft());
        self::assertSame(2, $layers[1]->getPosition());
        self::assertSame(80, $layers[1]->getDft());

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT category FROM coating_system_compliance WHERE system_id = ?',
            [(string) $this->systemId],
        );
        self::assertGreaterThan(0, count($rows), 'coating_system_compliance должен быть заполнен.');
    }

    public function test_insert_throws_when_system_not_found(): void
    {
        $cmd = new InsertLayerAtCommand(
            systemId: (string) Uuid::v7(),
            position: 1,
            coatingId: (string) $this->coatingId,
            dft: 80,
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/не найдена/');

        ($this->handler)($cmd);
    }

    public function test_insert_throws_when_coating_not_found(): void
    {
        $cmd = new InsertLayerAtCommand(
            systemId: (string) $this->systemId,
            position: 1,
            coatingId: (string) Uuid::v7(),
            dft: 80,
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/не найдено/');

        ($this->handler)($cmd);
    }
}
