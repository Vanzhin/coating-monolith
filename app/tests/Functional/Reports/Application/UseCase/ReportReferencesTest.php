<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Application\UseCase;

use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommand;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommandResult;
use App\Reports\Application\UseCase\Command\CreateProject\CreateProjectCommand;
use App\Reports\Application\UseCase\Command\CreateProject\CreateProjectCommandResult;
use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommand;
use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommandResult;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Reports\Domain\Repository\ProjectRepositoryInterface;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReportReferencesTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private CommandBusInterface $commandBus;
    private ReportRepositoryInterface $reports;
    private ProjectRepositoryInterface $projects;
    private CounterpartyRepositoryInterface $counterparties;
    private EntityManagerInterface $em;
    /** @var list<string> */
    private array $reportIds = [];
    /** @var list<string> */
    private array $projectIds = [];
    /** @var list<string> */
    private array $counterpartyIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->reports = $c->get(ReportRepositoryInterface::class);
        $this->projects = $c->get(ProjectRepositoryInterface::class);
        $this->counterparties = $c->get(CounterpartyRepositoryInterface::class);
        $this->em = $c->get(EntityManagerInterface::class);

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
        parent::tearDown();
    }

    private function counterparty(string $title): string
    {
        $r = $this->commandBus->execute(new CreateCounterpartyCommand($title));
        \assert($r instanceof CreateCounterpartyCommandResult);
        $this->counterpartyIds[] = $r->id;

        return $r->id;
    }

    private function project(string $title, string $counterpartyId): string
    {
        $r = $this->commandBus->execute(new CreateProjectCommand($title, $counterpartyId));
        \assert($r instanceof CreateProjectCommandResult);
        $this->projectIds[] = $r->id;

        return $r->id;
    }

    public function test_references_resolve_to_snapshots(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $customerId = $this->counterparty('Заказчик-'.$suffix);
        $contractorId = $this->counterparty('Подрядчик-'.$suffix);
        $projectId = $this->project('Проект-'.$suffix, $customerId);

        $result = $this->commandBus->execute(new CreateReportCommand(
            type: ReportType::TrialApplication,
            projectId: $projectId,
            customerId: $customerId,
            contractorId: $contractorId,
        ));
        \assert($result instanceof CreateReportCommandResult);
        $this->reportIds[] = $result->id;
        $this->em->clear();

        $report = $this->reports->findOneById($result->id);
        self::assertNotNull($report);

        $project = $report->getProject();
        self::assertNotNull($project);
        self::assertSame($projectId, $project->id);
        self::assertSame('Проект-'.$suffix, $project->title);

        $customer = $report->getCustomer();
        self::assertNotNull($customer);
        self::assertSame($customerId, $customer->id);
        self::assertSame('Заказчик-'.$suffix, $customer->title);

        $contractor = $report->getContractor();
        self::assertNotNull($contractor);
        self::assertSame($contractorId, $contractor->id);
        self::assertSame('Подрядчик-'.$suffix, $contractor->title);

        self::assertNull($report->getSystem());
    }

    public function test_unknown_project_throws(): void
    {
        $this->expectException(AppException::class);
        $this->commandBus->execute(new CreateReportCommand(
            type: ReportType::TrialApplication,
            projectId: 'no-such-project',
        ));
    }

    public function test_unknown_system_throws(): void
    {
        $this->expectException(AppException::class);
        $this->commandBus->execute(new CreateReportCommand(
            type: ReportType::TrialApplication,
            systemId: 'not-a-valid-system',
        ));
    }
}
