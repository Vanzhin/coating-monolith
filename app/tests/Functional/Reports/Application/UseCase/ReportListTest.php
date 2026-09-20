<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Application\UseCase;

use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommand;
use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommandResult;
use App\Reports\Application\UseCase\Command\SaveReportContent\SaveReportContentCommand;
use App\Reports\Application\UseCase\Query\GetPagedReports\GetPagedReportsQuery;
use App\Reports\Application\UseCase\Query\GetPagedReports\GetPagedReportsQueryResult;
use App\Reports\Domain\Aggregate\Report\ReportStatus;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Reports\Domain\Repository\ReportsFilter;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Tests\Functional\Coatings\Application\UseCase\Command\Layer\CoatingSystemLayerTestFixtureTrait;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Список отчётов: постраничная выборка (для «загрузить ещё»), фильтр по статусу и поиск по № акта.
 * Изоляция от чужих данных — по уникальному суффиксу в № акта.
 */
final class ReportListTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use CoatingSystemLayerTestFixtureTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private ReportRepositoryInterface $reports;
    private EntityManagerInterface $em;
    private string $suffix;
    /** @var list<string> */
    private array $reportIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->queryBus = $c->get(QueryBusInterface::class);
        $this->reports = $c->get(ReportRepositoryInterface::class);
        $this->em = $c->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(3));
        $this->setUpFixture($c, $this->em); // система обязательна: заводим одну (1 слой)
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
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        $this->tearDownFixture($this->em);
        parent::tearDown();
    }

    private function create(string $tag): string
    {
        $result = $this->commandBus->execute(new CreateReportCommand(
            type: ReportType::TrialApplication,
            actNumber: $tag.'-'.$this->suffix,
            systemId: (string) $this->systemId,
        ));
        \assert($result instanceof CreateReportCommandResult);
        $this->reportIds[] = $result->id;

        return $result->id;
    }

    private function list(?ReportStatus $status = null, ?string $search = null): GetPagedReportsQueryResult
    {
        $result = $this->queryBus->execute(new GetPagedReportsQuery(
            new ReportsFilter(pager: Pager::fromPage(1, 50), status: $status, search: $search ?? $this->suffix),
        ));
        \assert($result instanceof GetPagedReportsQueryResult);

        return $result;
    }

    public function test_lists_created_reports(): void
    {
        $this->create('A');
        $this->create('B');
        $this->em->clear();

        $result = $this->list();
        self::assertCount(2, $result->reports);
        self::assertSame(2, $result->pager->total_items);
        self::assertSame(ReportStatus::Created->value, $result->reports[0]->status);
    }

    public function test_filters_by_status(): void
    {
        $inWorkId = $this->create('A');
        $this->create('B');
        $this->commandBus->execute(new SaveReportContentCommand($inWorkId, ['notes' => ['text' => 'x']])); // → В работе
        $this->em->clear();

        self::assertCount(1, $this->list(status: ReportStatus::InWork)->reports);
        self::assertCount(1, $this->list(status: ReportStatus::Created)->reports);
    }

    public function test_searches_by_act_number(): void
    {
        $this->create('ALPHA');
        $this->create('BETA');
        $this->em->clear();

        $result = $this->list(search: 'ALPHA-'.$this->suffix);
        self::assertCount(1, $result->reports);
        self::assertSame('ALPHA-'.$this->suffix, $result->reports[0]->actNumber);
    }
}
