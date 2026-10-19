<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\DTO\Dashboard\PersonRowDTO;
use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommandResult;
use App\Compliance\Application\UseCase\Query\Dashboard\ComplianceDashboardFilter;
use App\Compliance\Application\UseCase\Query\Dashboard\GetComplianceOverviewQuery;
use App\Compliance\Application\UseCase\Query\Dashboard\GetComplianceOverviewQueryResult;
use App\Compliance\Application\UseCase\Query\Dashboard\GetPagedComplianceQuery;
use App\Compliance\Application\UseCase\Query\Dashboard\GetPagedComplianceQueryResult;
use App\Compliance\Domain\Aggregate\ProfileCompliance\IssuanceLine;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Type\ComplianceBucket;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

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

    public function test_no_fact_person_is_missing_in_list_and_kpi(): void
    {
        ['profileId' => $profileId] = $this->enrollCompliance();

        $list = $this->paged(new ComplianceDashboardFilter());
        $row = $this->rowOf($list, $profileId);
        self::assertNotNull($row, 'человек должен быть в списке');
        self::assertSame(ComplianceBucket::Missing->value, $row->worstBucket, 'нет выданного факта ⇒ не выдано');
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
            null, 'Журнал инструктажа', 'periodic', [$pr->profile->positionId],
            [['label' => 'Инструктаж', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '1', 'unit' => 'pcs', 'basis' => 'п.6']],
        ));
        \assert($req2 instanceof SaveRequirementCommandResult);
        static::getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profileId));

        $overview = $this->overview(new ComplianceDashboardFilter());
        self::assertSame(2, $overview->kpi->missing, 'два требования одного человека = 2 в «не исполнено» (счёт по человек×требование)');
        self::assertNotEmpty($overview->departments);
        self::assertSame(1, $overview->departments[0]->peopleCount, 'в отделе один человек');
        self::assertSame(2, $overview->departments[0]->counts->total(), 'но два требования-экземпляра');
    }

    public function test_issued_fact_becomes_ok(): void
    {
        ['profileId' => $profileId, 'requirementId' => $requirementId, 'key' => $key] = $this->enrollCompliance();

        // Прямая дозапись факта в журнал (без акта-черновика) — журнальная модель выдачи.
        $pc = $this->repo->findByProfile($profileId);
        self::assertNotNull($pc);
        $pc->assignCardNumber('CARD-1'); // инвариант выдачи — № карточки обязателен
        // Вариант B: агрегат нормы не материализует — инвариант выдачи резолвит норму из переданного Requirement.
        $requirement = static::getContainer()->get(RequirementRepositoryInterface::class)->findOneById($requirementId);
        self::assertNotNull($requirement);
        $pc->recordIssuance(
            $requirement,
            new IssuanceLine(Uuid::v7(), $key, new \DateTimeImmutable('2026-05-20'), new Quantity(10.0, Unit::Pair)),
        );
        $this->repo->add($pc);
        static::getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profileId));
        // Читатель теперь читает read-only ObligationProjection через DQL: без clear() identity map отдаёт
        // СТАРЫЙ закэшированный инстанс строки проекции (upsertMany пишет DBAL мимо ORM, не обновляет его
        // in-memory), см. докблок ObligationProjectionRepository.
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $row = $this->rowOf($this->paged(new ComplianceDashboardFilter()), $profileId);
        self::assertNotNull($row);
        self::assertSame(ComplianceBucket::Ok->value, $row->worstBucket, 'выдан факт в срок, без акта ⇒ в норме');
    }

    public function test_status_filter_missing_includes_type_ok_excludes(): void
    {
        ['profileId' => $profileId] = $this->enrollCompliance();

        self::assertNotNull($this->rowOf($this->paged(new ComplianceDashboardFilter(statusBucket: ComplianceBucket::Missing)), $profileId));
        self::assertNull($this->rowOf($this->paged(new ComplianceDashboardFilter(statusBucket: ComplianceBucket::Ok)), $profileId));
    }

    public function test_type_filter_periodic_excludes_material_person(): void
    {
        ['profileId' => $profileId] = $this->enrollCompliance();

        // у человека только материальное требование → фильтр по периодическим его убирает
        self::assertNull($this->rowOf($this->paged(new ComplianceDashboardFilter(type: ComplianceType::Periodic)), $profileId));
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

        // Re-save выше без round-trip id пересоздал id позиций → ключ «Перчаток» теперь другой; берём актуальный
        // (в проде форма правки возвращает id, здесь команда собрана руками без него).
        $glovesKey = $this->obligationKeyFor($reqId, 'Перчатки');

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
