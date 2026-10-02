<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\DTO\Dashboard\PersonRowDTO;
use App\Compliance\Application\ReadModel\ComplianceBucket;
use App\Compliance\Application\UseCase\Command\FormDraft\FormDraftCommand;
use App\Compliance\Application\UseCase\Command\SignDraft\SignDraftCommand;
use App\Compliance\Application\UseCase\Query\Dashboard\ComplianceDashboardFilter;
use App\Compliance\Application\UseCase\Query\Dashboard\GetComplianceOverviewQuery;
use App\Compliance\Application\UseCase\Query\Dashboard\GetComplianceOverviewQueryResult;
use App\Compliance\Application\UseCase\Query\Dashboard\GetPagedComplianceQuery;
use App\Compliance\Application\UseCase\Query\Dashboard\GetPagedComplianceQueryResult;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Type\ComplianceType;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ComplianceDashboardQueryTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private ProfileComplianceRepositoryInterface $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->queryBus = $c->get(QueryBusInterface::class);
        $this->repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $this->authenticateAsSystem(); // isManager → админ-скоуп (видит всех)
    }

    public function test_unsigned_person_is_missing_in_list_and_kpi(): void
    {
        ['profileId' => $profileId] = $this->enrollCompliance();

        $list = $this->paged(new ComplianceDashboardFilter());
        $row = $this->rowOf($list, $profileId);
        self::assertNotNull($row, 'человек должен быть в списке');
        self::assertSame(ComplianceBucket::Missing->value, $row->worstBucket, 'не подписан ⇒ не выдано');
        self::assertNotEmpty($row->groups);

        $overview = $this->overview(new ComplianceDashboardFilter());
        self::assertGreaterThanOrEqual(1, $overview->kpi->missing);
        self::assertNotEmpty($overview->departments);
        self::assertNotEmpty($overview->requirements);
    }

    public function test_signed_and_fulfilled_becomes_ok(): void
    {
        ['profileId' => $profileId, 'requirementId' => $reqId, 'key' => $key] = $this->enrollCompliance();

        // Оформление черновика (скан, кол-во = норма) пишет факт + подписывает акт → active.
        $this->commandBus->execute(new FormDraftCommand($profileId, $reqId));
        $draft = $this->repo->findByProfile($profileId)?->openDraftFor($reqId);
        self::assertNotNull($draft);
        $this->commandBus->execute(new SignDraftCommand(
            $profileId, $draft->getId(), '2026-05-20',
            [['obligationKey' => $key, 'amount' => '10', 'unit' => 'pair']],
            $this->stageComplianceScan(),
        ));

        $row = $this->rowOf($this->paged(new ComplianceDashboardFilter()), $profileId);
        self::assertNotNull($row);
        self::assertSame(ComplianceBucket::Ok->value, $row->worstBucket, 'подписан + выдан в срок ⇒ в норме');
    }

    public function test_status_filter_missing_includes_type_ok_excludes(): void
    {
        ['profileId' => $profileId] = $this->enrollCompliance();

        self::assertNotNull($this->rowOf($this->paged(new ComplianceDashboardFilter(statusBucket: ComplianceBucket::Missing)), $profileId));
        self::assertNull($this->rowOf($this->paged(new ComplianceDashboardFilter(statusBucket: ComplianceBucket::Ok)), $profileId));
    }

    public function test_type_filter_nonmaterial_excludes_material_person(): void
    {
        ['profileId' => $profileId] = $this->enrollCompliance();

        // у человека только материальное требование → фильтр по процедурам его убирает
        self::assertNull($this->rowOf($this->paged(new ComplianceDashboardFilter(type: ComplianceType::NonMaterial)), $profileId));
    }

    private function paged(ComplianceDashboardFilter $filter): GetPagedComplianceQueryResult
    {
        /** @var GetPagedComplianceQueryResult $result */
        $result = $this->queryBus->execute(new GetPagedComplianceQuery($filter));

        return $result;
    }

    private function overview(ComplianceDashboardFilter $filter): GetComplianceOverviewQueryResult
    {
        /** @var GetComplianceOverviewQueryResult $result */
        $result = $this->queryBus->execute(new GetComplianceOverviewQuery($filter));

        return $result;
    }

    private function rowOf(GetPagedComplianceQueryResult $result, string $profileId): ?PersonRowDTO
    {
        foreach ($result->people as $row) {
            if ($row->profileId === $profileId) {
                return $row;
            }
        }

        return null;
    }
}
