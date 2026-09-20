<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Application\UseCase;

use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommand;
use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommandResult;
use App\Reports\Application\UseCase\Command\DeleteReport\DeleteReportCommand;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Functional\Coatings\Application\UseCase\Command\Layer\CoatingSystemLayerTestFixtureTrait;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Удаление отчёта: владелец/админ удаляет неутверждённый; несуществующий — 404-исключение.
 * Заморозку (утверждённый нельзя) проверяет доменный юнит-тест assertDeletable.
 */
final class DeleteReportTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use CoatingSystemLayerTestFixtureTrait;

    private CommandBusInterface $commandBus;
    private ReportRepositoryInterface $reports;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->reports = $c->get(ReportRepositoryInterface::class);
        $this->em = $c->get(EntityManagerInterface::class);
        $this->setUpFixture($c, $this->em);
        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->tearDownFixture($this->em);
        parent::tearDown();
    }

    public function test_delete_removes_report(): void
    {
        $result = $this->commandBus->execute(new CreateReportCommand(ReportType::TrialApplication, systemId: (string) $this->systemId));
        \assert($result instanceof CreateReportCommandResult);
        $id = $result->id;
        self::assertNotNull($this->reports->findOneById($id));

        $this->commandBus->execute(new DeleteReportCommand($id));
        $this->em->clear();

        self::assertNull($this->reports->findOneById($id));
    }

    public function test_delete_missing_report_throws(): void
    {
        $this->expectException(AppException::class);
        $this->commandBus->execute(new DeleteReportCommand('00000000-0000-0000-0000-000000000000'));
    }
}
