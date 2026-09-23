<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Application\UseCase;

use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommand;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommandResult;
use App\Reports\Application\UseCase\Command\CreateProject\CreateProjectCommand;
use App\Reports\Application\UseCase\Command\CreateProject\CreateProjectCommandResult;
use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommand;
use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommandResult;
use App\Reports\Application\UseCase\Command\UpdateReportHeader\UpdateReportHeaderCommand;
use App\Reports\Application\UseCase\Query\GetPagedReports\GetPagedReportsQuery;
use App\Reports\Application\UseCase\Query\GetPagedReports\GetPagedReportsQueryResult;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Reports\Domain\Repository\ProjectRepositoryInterface;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Reports\Domain\Repository\ReportsFilter;
use App\Reports\Domain\Repository\ReportsSort;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use App\Tests\Functional\Coatings\Application\UseCase\Command\Layer\CoatingSystemLayerTestFixtureTrait;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

/**
 * Фасеты списка отчётов: owner (скалярная колонка, IN) и заказчик/подрядчик/проект (id внутри JSONB
 * Reference-колонок, JSONB_GET_TEXT ... IN). Проверяем OR внутри фасета и AND между фасетами.
 * Изоляция от чужих данных — уникальный суффикс в № акта (list() всегда фильтрует по нему).
 */
final class ReportFilterTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use CoatingSystemLayerTestFixtureTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private ReportRepositoryInterface $reports;
    private CounterpartyRepositoryInterface $counterparties;
    private ProjectRepositoryInterface $projects;
    private EntityManagerInterface $em;
    private string $suffix;
    /** @var list<string> */
    private array $reportIds = [];
    /** @var list<string> */
    private array $counterpartyIds = [];
    /** @var list<string> */
    private array $projectIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->queryBus = $c->get(QueryBusInterface::class);
        $this->reports = $c->get(ReportRepositoryInterface::class);
        $this->counterparties = $c->get(CounterpartyRepositoryInterface::class);
        $this->projects = $c->get(ProjectRepositoryInterface::class);
        $this->em = $c->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(3));
        $this->setUpFixture($c, $this->em);
        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        try {
            foreach ($this->reportIds as $id) {
                if (null !== ($r = $this->reports->findOneById($id))) {
                    $this->reports->remove($r);
                }
            }
            foreach ($this->projectIds as $id) {
                if (null !== ($p = $this->projects->findOneById($id))) {
                    $this->projects->remove($p);
                }
            }
            foreach ($this->counterpartyIds as $id) {
                if (null !== ($cp = $this->counterparties->findOneById($id))) {
                    $this->counterparties->remove($cp);
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        $this->tearDownFixture($this->em);
        parent::tearDown();
    }

    public function test_filters_by_customer_contractor_project_and_owner(): void
    {
        $cpA = $this->counterparty('A');
        $cpB = $this->counterparty('B');
        $cpC = $this->counterparty('C');
        $projP = $this->project('P', $cpA);

        // r1: заказчик A, подрядчик B, проект P. r2: заказчик A, подрядчик C, без проекта.
        $r1 = $this->create('R1');
        $this->attachRefs($r1, customerId: $cpA, contractorId: $cpB, projectId: $projP);
        $r2 = $this->create('R2');
        $this->attachRefs($r2, customerId: $cpA, contractorId: $cpC);
        $this->em->clear();

        $ownerId = $this->reports->findOneById($r1)->getOwnerId();

        // Заказчик A — у обоих; C — ни у кого (C только подрядчик).
        self::assertSame(2, $this->countMatching(customerIds: [$cpA]));
        self::assertSame(0, $this->countMatching(customerIds: [$cpC]));

        // Подрядчик: B → только r1; B+C → оба (OR внутри фасета).
        self::assertSame(1, $this->countMatching(contractorIds: [$cpB]));
        self::assertSame(2, $this->countMatching(contractorIds: [$cpB, $cpC]));

        // Проект P → только r1.
        self::assertSame(1, $this->countMatching(projectIds: [$projP]));

        // AND между фасетами: заказчик A И подрядчик B → только r1.
        self::assertSame(1, $this->countMatching(customerIds: [$cpA], contractorIds: [$cpB]));

        // Owner: свой id (админ создал оба) → 2; случайный ULID → 0.
        self::assertSame(2, $this->countMatching(ownerIds: [$ownerId]));
        self::assertSame(0, $this->countMatching(ownerIds: [(string) new Ulid()]));
    }

    public function test_filters_by_type(): void
    {
        $this->create('T', ReportType::TrialApplication);
        $this->create('R', ReportType::ReferenceArea);
        $this->em->clear();

        self::assertSame(2, $this->countMatching());
        self::assertSame(1, $this->countMatching(type: ReportType::TrialApplication));
        self::assertSame(1, $this->countMatching(type: ReportType::ReferenceArea));
    }

    public function test_sorts_by_created_date(): void
    {
        $older = $this->create('OLD');
        $newer = $this->create('NEW');
        // Разводим createdAt детерминированно — в одном тесте у обоих иначе одна секунда.
        $this->setCreatedAt($older, new \DateTimeImmutable('2020-01-01 10:00:00'));
        $this->setCreatedAt($newer, new \DateTimeImmutable('2024-01-01 10:00:00'));
        $this->em->clear();

        // Дефолт — createdAt DESC (сначала новые).
        self::assertSame([$newer, $older], $this->orderedIds(ReportsSort::DEFAULT));
        // Сначала старые.
        self::assertSame([$older, $newer], $this->orderedIds(ReportsSort::CREATED_ASC));
    }

    private function setCreatedAt(string $reportId, \DateTimeImmutable $dt): void
    {
        $report = $this->reports->findOneById($reportId);
        \assert(null !== $report);
        (new \ReflectionProperty($report, 'createdAt'))->setValue($report, $dt);
        $this->em->flush();
    }

    /** @return list<string> */
    private function orderedIds(ReportsSort $sort): array
    {
        $result = $this->queryBus->execute(new GetPagedReportsQuery(new ReportsFilter(
            pager: Pager::fromPage(1, 50),
            search: $this->suffix,
            sort: $sort,
        )));
        \assert($result instanceof GetPagedReportsQueryResult);

        return array_map(static fn ($r): string => $r->id, $result->reports);
    }

    private function counterparty(string $tag): string
    {
        $result = $this->commandBus->execute(new CreateCounterpartyCommand($tag.'-'.$this->suffix));
        \assert($result instanceof CreateCounterpartyCommandResult);
        $this->counterpartyIds[] = $result->id;

        return $result->id;
    }

    private function project(string $tag, string $counterpartyId): string
    {
        $result = $this->commandBus->execute(new CreateProjectCommand($tag.'-'.$this->suffix, $counterpartyId));
        \assert($result instanceof CreateProjectCommandResult);
        $this->projectIds[] = $result->id;

        return $result->id;
    }

    private function create(string $tag, ReportType $type = ReportType::TrialApplication): string
    {
        $result = $this->commandBus->execute(new CreateReportCommand(
            type: $type,
            actNumber: $tag.'-'.$this->suffix,
            systemId: (string) $this->systemId,
        ));
        \assert($result instanceof CreateReportCommandResult);
        $this->reportIds[] = $result->id;

        return $result->id;
    }

    private function attachRefs(string $reportId, ?string $customerId = null, ?string $contractorId = null, ?string $projectId = null): void
    {
        // Сохраняем № акта: updateHeader затирает его значением команды (как реальный FillAction,
        // где № шлётся вместе с реквизитами). Иначе он обнулился бы и отчёт выпал бы из suffix-поиска.
        $actNumber = $this->reports->findOneById($reportId)?->getActNumber();
        $this->commandBus->execute(new UpdateReportHeaderCommand(
            reportId: $reportId,
            actNumber: $actNumber,
            customerId: $customerId,
            contractorId: $contractorId,
            projectId: $projectId,
        ));
    }

    /**
     * @param list<string> $ownerIds
     * @param list<string> $customerIds
     * @param list<string> $contractorIds
     * @param list<string> $projectIds
     */
    private function countMatching(array $ownerIds = [], array $customerIds = [], array $contractorIds = [], array $projectIds = [], ?ReportType $type = null): int
    {
        $result = $this->queryBus->execute(new GetPagedReportsQuery(new ReportsFilter(
            pager: Pager::fromPage(1, 50),
            ownerIds: new StringCollection(...$ownerIds),
            customerIds: new StringCollection(...$customerIds),
            contractorIds: new StringCollection(...$contractorIds),
            projectIds: new StringCollection(...$projectIds),
            type: $type,
            search: $this->suffix,
        )));
        \assert($result instanceof GetPagedReportsQueryResult);

        return $result->pager->total_items;
    }
}
