<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Command\Layer;

use App\Coatings\Application\UseCase\Command\AppendLayer\AppendLayerCommand;
use App\Coatings\Application\UseCase\Command\AppendLayer\AppendLayerCommandHandler;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AppendLayerTest extends KernelTestCase
{
    use CoatingSystemLayerTestFixtureTrait;
    use AuthenticatesActorTrait;

    private AppendLayerCommandHandler $handler;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(AppendLayerCommandHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->setUpFixture($container, $this->em);

        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture(static::getContainer()->get(EntityManagerInterface::class));
        parent::tearDown();
    }

    public function test_append_layer_adds_layer_to_system(): void
    {
        $cmd = new AppendLayerCommand(
            systemId: (string) $this->systemId,
            coatingId: (string) $this->coatingId,
            dft: 80,
            colorId: (string) $this->colorId,
        );

        $result = ($this->handler)($cmd);

        self::assertNotEmpty($result->layerId);

        $this->em->clear();

        $loaded = $this->em->find(CoatingSystem::class, $this->systemId);
        self::assertNotNull($loaded);
        self::assertSame(2, $loaded->layerCount());

        $layers = array_values($loaded->getLayers()->toArray());
        self::assertSame(1, $layers[0]->getPosition());
        self::assertSame(2, $layers[1]->getPosition());
        self::assertSame(80, $layers[1]->getDft());

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT category FROM coating_system_compliance WHERE system_id = ?',
            [(string) $this->systemId],
        );
        self::assertGreaterThan(0, count($rows), 'coating_system_compliance должен быть заполнен.');
    }

    public function test_append_layer_updates_coating_system_search_cache(): void
    {
        $cmd = new AppendLayerCommand(
            systemId: (string) $this->systemId,
            coatingId: (string) $this->coatingId,
            dft: 100,
            colorId: (string) $this->colorId,
        );

        ($this->handler)($cmd);

        $this->em->clear();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT system_id FROM coating_system_search WHERE system_id = ?',
            [(string) $this->systemId],
        );
        self::assertNotFalse($row, 'coating_system_search должна содержать строку для системы после добавления слоя.');
    }

    public function test_append_throws_when_system_not_found(): void
    {
        $cmd = new AppendLayerCommand(
            systemId: (string) Uuid::v7(),
            coatingId: (string) $this->coatingId,
            dft: 80,
            colorId: (string) $this->colorId,
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/не найдена/');

        ($this->handler)($cmd);
    }

    public function test_append_throws_when_coating_not_found(): void
    {
        $cmd = new AppendLayerCommand(
            systemId: (string) $this->systemId,
            coatingId: (string) Uuid::v7(),
            dft: 80,
            colorId: (string) $this->colorId,
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/не найдено/');

        ($this->handler)($cmd);
    }
}
