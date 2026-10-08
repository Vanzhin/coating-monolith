<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\DTO\Dashboard\PersonRowDTO;
use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\FormDraft\FormDraftCommand;
use App\Compliance\Application\UseCase\Command\SaveDraft\SaveDraftCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommandResult;
use App\Compliance\Application\UseCase\Query\Dashboard\ComplianceDashboardFilter;
use App\Compliance\Application\UseCase\Query\Dashboard\GetComplianceOverviewQuery;
use App\Compliance\Application\UseCase\Query\Dashboard\GetComplianceOverviewQueryResult;
use App\Compliance\Application\UseCase\Query\Dashboard\GetPagedComplianceQuery;
use App\Compliance\Application\UseCase\Query\Dashboard\GetPagedComplianceQueryResult;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Type\ComplianceBucket;
use App\Compliance\Domain\Type\ComplianceType;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
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

    public function test_kpi_counts_each_requirement_per_person_and_dept_keeps_headcount(): void
    {
        ['profileId' => $profileId] = $this->enrollCompliance(); // 1-е требование (материальное) на должности человека

        /** @var GetProfileQueryResult $pr */
        $pr = $this->queryBus->execute(new GetProfileQuery($profileId));
        self::assertNotNull($pr->profile);

        // 2-е требование на ту же должность → у человека ДВА требования, оба не исполнены (не подписаны).
        $req2 = $this->commandBus->execute(new SaveRequirementCommand(
            null, 'Журнал инструктажа', 'non_material', [$pr->profile->positionId],
            [['label' => 'Инструктаж', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'basis' => 'п.6']],
        ));
        \assert($req2 instanceof SaveRequirementCommandResult);
        static::getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profileId));

        $overview = $this->overview(new ComplianceDashboardFilter());
        self::assertSame(2, $overview->kpi->missing, 'два требования одного человека = 2 в «не исполнено» (счёт по человек×требование)');
        self::assertNotEmpty($overview->departments);
        self::assertSame(1, $overview->departments[0]->peopleCount, 'в отделе один человек');
        self::assertSame(2, $overview->departments[0]->counts->total(), 'но два требования-экземпляра');
    }

    public function test_signed_and_fulfilled_becomes_ok(): void
    {
        ['profileId' => $profileId, 'requirementId' => $reqId, 'key' => $key] = $this->enrollCompliance();

        // Оформление черновика (скан, кол-во = норма) пишет факт + подписывает акт → active.
        $this->commandBus->execute(new FormDraftCommand($profileId, $reqId));
        $draft = $this->repo->findByProfile($profileId)?->openDraftFor($reqId);
        self::assertNotNull($draft);
        $this->commandBus->execute(new SaveDraftCommand(
            $profileId, $draft->getId(), '2026-05-20',
            [['obligationKey' => $key, 'amount' => '10', 'unit' => 'pair']],
            'К-1', 'Петров П. П.',
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

    public function test_overdue_counted_and_listed_even_when_hidden_behind_missing_in_same_requirement(): void
    {
        // Кейс «Захарова»: в ОДНОМ требовании Перчатки просрочены (выданы давно), Каска не выдана. worst и группы,
        // и человека = Missing. Баг счёта по worst: просроченное пряталось за невыданным → чип overdue=0, список пуст.
        // Ждём: счёт/фильтр по ПОЗИЦИЯМ — overdue > 0 и человек в списке overdue (и в списке missing тоже).
        ['profileId' => $profileId, 'requirementId' => $reqId, 'key' => $glovesKey] = $this->enrollCompliance('Перчатки');

        /** @var GetProfileQueryResult $pr */
        $pr = $this->queryBus->execute(new GetProfileQuery($profileId));
        self::assertNotNull($pr->profile);
        // То же требование → две позиции: Перчатки (как в enroll) + Каска.
        $this->commandBus->execute(new SaveRequirementCommand(
            $reqId, 'Личная карточка учёта выдачи СИЗ', 'material', [$pr->profile->positionId],
            [
                ['label' => 'Перчатки', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5'],
                ['label' => 'Каска', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '1', 'unit' => 'pcs', 'basis' => 'п.7'],
            ],
        ));
        static::getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profileId));

        // Выдаём ТОЛЬКО Перчатки, задним числом (2020 → срок 2021 < now) → Overdue; Каска остаётся Missing.
        $this->issueCard($profileId, $reqId, $glovesKey, '10', '2020-01-01');

        $row = $this->rowOf($this->paged(new ComplianceDashboardFilter()), $profileId);
        self::assertNotNull($row);
        self::assertSame(ComplianceBucket::Missing->value, $row->worstBucket, 'worst человека — Missing (Каска)');

        $kpi = $this->overview(new ComplianceDashboardFilter())->kpi;
        self::assertGreaterThanOrEqual(1, $kpi->overdue, 'просроченная позиция считается, несмотря на невыданную в той же карточке');
        self::assertGreaterThanOrEqual(1, $kpi->missing);

        self::assertNotNull(
            $this->rowOf($this->paged(new ComplianceDashboardFilter(statusBucket: ComplianceBucket::Overdue)), $profileId),
            'человек с просроченной позицией должен попадать в список overdue',
        );
        self::assertNotNull(
            $this->rowOf($this->paged(new ComplianceDashboardFilter(statusBucket: ComplianceBucket::Missing)), $profileId),
            'и в список missing (есть невыданная позиция)',
        );
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
