<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Command\Layer;

use App\Coatings\Application\UseCase\Command\AppendLayer\AppendLayerCommand;
use App\Coatings\Application\UseCase\Command\AppendLayer\AppendLayerCommandHandler;
use App\Coatings\Application\UseCase\Command\MoveLayer\MoveLayerCommand;
use App\Coatings\Application\UseCase\Command\MoveLayer\MoveLayerCommandHandler;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class MoveLayerTest extends KernelTestCase
{
    use CoatingSystemLayerTestFixtureTrait;
    use AuthenticatesActorTrait;

    private MoveLayerCommandHandler $handler;
    private AppendLayerCommandHandler $appendHandler;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(MoveLayerCommandHandler::class);
        $this->appendHandler = $container->get(AppendLayerCommandHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);

        // Мутация ниже (appendHandler) авторизуется через AccessControl —
        // системный актор должен быть в контексте до её вызова.
        $this->authenticateAsSystem();

        $this->setUpFixture($container, $this->em);

        // Add a second layer so we have 2 layers to move between.
        $appendCmd = new AppendLayerCommand(
            systemId: (string) $this->systemId,
            coatingId: (string) $this->coatingId,
            dft: 100,
            colorId: (string) $this->colorId,
        );
        ($this->appendHandler)($appendCmd);
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture(static::getContainer()->get(EntityManagerInterface::class));
        parent::tearDown();
    }

    public function test_move_layer_from_2_to_1_swaps_positions(): void
    {
        $cmd = new MoveLayerCommand(
            systemId: (string) $this->systemId,
            from: 2,
            to: 1,
        );

        ($this->handler)($cmd);

        $this->em->clear();

        $loaded = $this->em->find(CoatingSystem::class, $this->systemId);
        self::assertNotNull($loaded);
        self::assertSame(2, $loaded->layerCount());

        $layers = array_values($loaded->getLayers()->toArray());
        self::assertSame(1, $layers[0]->getPosition());
        self::assertSame(100, $layers[0]->getDft());
        self::assertSame(2, $layers[1]->getPosition());
        self::assertSame(80, $layers[1]->getDft());

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT category FROM coating_system_compliance WHERE system_id = ?',
            [(string) $this->systemId],
        );
        self::assertGreaterThan(0, count($rows), 'coating_system_compliance должен быть заполнен.');
    }

    public function test_move_throws_when_system_not_found(): void
    {
        $cmd = new MoveLayerCommand(
            systemId: (string) Uuid::v7(),
            from: 1,
            to: 2,
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/не найдена/');

        ($this->handler)($cmd);
    }
}
