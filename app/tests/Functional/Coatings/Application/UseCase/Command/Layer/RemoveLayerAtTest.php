<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Command\Layer;

use App\Coatings\Application\UseCase\Command\RemoveLayerAt\RemoveLayerAtCommand;
use App\Coatings\Application\UseCase\Command\RemoveLayerAt\RemoveLayerAtCommandHandler;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RemoveLayerAtTest extends KernelTestCase
{
    use CoatingSystemLayerTestFixtureTrait;

    private RemoveLayerAtCommandHandler $handler;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(RemoveLayerAtCommandHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->setUpFixture($container, $this->em);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture(static::getContainer()->get(EntityManagerInterface::class));
        parent::tearDown();
    }

    public function test_remove_layer_at_position_1_removes_layer(): void
    {
        $cmd = new RemoveLayerAtCommand(
            systemId: (string) $this->systemId,
            position: 1,
        );

        ($this->handler)($cmd);

        $this->em->clear();

        $loaded = $this->em->find(CoatingSystem::class, $this->systemId);
        self::assertNotNull($loaded);
        self::assertSame(0, $loaded->layerCount());

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT category FROM coating_system_compliance WHERE system_id = ?',
            [(string) $this->systemId],
        );
        self::assertCount(0, $rows, 'coating_system_compliance должен быть очищен для пустой системы.');
    }

    public function test_remove_throws_when_system_not_found(): void
    {
        $cmd = new RemoveLayerAtCommand(
            systemId: (string) Uuid::v7(),
            position: 1,
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/не найдена/');

        ($this->handler)($cmd);
    }

    public function test_remove_throws_when_position_not_found(): void
    {
        $cmd = new RemoveLayerAtCommand(
            systemId: (string) $this->systemId,
            position: 99,
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/не найден/');

        ($this->handler)($cmd);
    }
}
