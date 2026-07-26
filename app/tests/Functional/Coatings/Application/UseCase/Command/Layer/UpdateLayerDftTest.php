<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Command\Layer;

use App\Coatings\Application\UseCase\Command\UpdateLayerDft\UpdateLayerDftCommand;
use App\Coatings\Application\UseCase\Command\UpdateLayerDft\UpdateLayerDftCommandHandler;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class UpdateLayerDftTest extends KernelTestCase
{
    use CoatingSystemLayerTestFixtureTrait;

    private UpdateLayerDftCommandHandler $handler;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(UpdateLayerDftCommandHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->setUpFixture($container, $this->em);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture(static::getContainer()->get(EntityManagerInterface::class));
        parent::tearDown();
    }

    public function test_update_layer_dft_persists_new_value(): void
    {
        $cmd = new UpdateLayerDftCommand(
            systemId: (string) $this->systemId,
            position: 1,
            dft: 120,
        );

        ($this->handler)($cmd);

        $this->em->clear();

        $loaded = $this->em->find(CoatingSystem::class, $this->systemId);
        self::assertNotNull($loaded);
        self::assertSame(1, $loaded->layerCount());
        self::assertSame(120, $loaded->getLayers()->first()->getDft());

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT category FROM coating_system_compliance WHERE system_id = ?',
            [(string) $this->systemId],
        );
        self::assertGreaterThan(0, count($rows), 'coating_system_compliance должен быть заполнен.');
    }

    public function test_update_throws_when_system_not_found(): void
    {
        $cmd = new UpdateLayerDftCommand(
            systemId: (string) Uuid::v7(),
            position: 1,
            dft: 120,
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/не найдена/');

        ($this->handler)($cmd);
    }

    public function test_update_throws_when_position_not_found(): void
    {
        $cmd = new UpdateLayerDftCommand(
            systemId: (string) $this->systemId,
            position: 99,
            dft: 120,
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/не найден/');

        ($this->handler)($cmd);
    }
}
