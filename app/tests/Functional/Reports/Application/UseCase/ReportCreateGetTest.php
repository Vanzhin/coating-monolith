<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Application\UseCase;

use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommand;
use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommandResult;
use App\Reports\Application\UseCase\Query\GetReport\GetReportQuery;
use App\Reports\Application\UseCase\Query\GetReport\GetReportQueryResult;
use App\Reports\Domain\Aggregate\Report\ReportStatus;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Tests\Functional\Coatings\Application\UseCase\Command\Layer\CoatingSystemLayerTestFixtureTrait;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReportCreateGetTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use CoatingSystemLayerTestFixtureTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private ReportRepositoryInterface $repo;
    private EntityManagerInterface $em;
    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->queryBus = $container->get(QueryBusInterface::class);
        $this->repo = $container->get(ReportRepositoryInterface::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->setUpFixture($container, $this->em); // система обязательна: заводим одну (1 слой)

        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        try {
            foreach ($this->createdIds as $id) {
                $report = $this->repo->findOneById($id);
                if (null !== $report) {
                    $this->repo->remove($report);
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        $this->tearDownFixture($this->em);
        parent::tearDown();
    }

    private function create(?ReportType $type, ?\DateTimeImmutable $date = null, ?string $act = null): string
    {
        $result = $this->commandBus->execute(new CreateReportCommand($type, $date, $act, systemId: (string) $this->systemId));
        \assert($result instanceof CreateReportCommandResult);
        $this->createdIds[] = $result->id;

        return $result->id;
    }

    public function test_create_persists_header_and_starts_created(): void
    {
        $id = $this->create(ReportType::TrialApplication, new \DateTimeImmutable('2026-08-05'), '  АКТ-01  ');
        $this->em->clear();

        $report = $this->repo->findOneById($id);
        self::assertNotNull($report);
        self::assertSame(ReportStatus::Created, $report->getStatus());
        self::assertSame(ReportType::TrialApplication, $report->getType());
        self::assertSame('АКТ-01', $report->getActNumber()); // triм
        self::assertSame('2026-08-05', $report->getReportDate()?->format('Y-m-d'));
        self::assertNotSame('', $report->getOwnerId());
        // Система обязательна → её слои засеяны в план сразу при создании.
        $content = $report->getContent();
        self::assertArrayHasKey('system', $content);
        self::assertIsArray($content['system']['layers']);
        self::assertCount(1, $content['system']['layers']);
    }

    public function test_type_can_be_null(): void
    {
        $id = $this->create(null);
        $this->em->clear();

        $report = $this->repo->findOneById($id);
        self::assertNotNull($report);
        self::assertNull($report->getType());
    }

    public function test_get_returns_dto_with_labels(): void
    {
        $id = $this->create(ReportType::ReferenceArea);

        $result = $this->queryBus->execute(new GetReportQuery($id));
        \assert($result instanceof GetReportQueryResult);

        self::assertNotNull($result->report);
        self::assertSame('created', $result->report->status);
        self::assertSame('Создан', $result->report->statusLabel);
        self::assertSame('reference_area', $result->report->typeKey);
        self::assertSame('Акт выкрасов эталонного участка', $result->report->typeLabel);
    }

    public function test_status_transition_persists(): void
    {
        $id = $this->create(ReportType::TrialApplication);

        $report = $this->repo->findOneById($id);
        self::assertNotNull($report);
        $report->startWork(new \DateTimeImmutable());
        $this->repo->add($report);
        $this->em->clear();

        self::assertSame(ReportStatus::InWork, $this->repo->findOneById($id)?->getStatus());
    }

    public function test_get_missing_returns_null(): void
    {
        $result = $this->queryBus->execute(new GetReportQuery('00000000-0000-0000-0000-000000000000'));
        \assert($result instanceof GetReportQueryResult);
        self::assertNull($result->report);
    }
}
