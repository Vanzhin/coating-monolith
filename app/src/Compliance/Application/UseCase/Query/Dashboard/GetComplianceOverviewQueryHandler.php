<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\Dashboard;

use App\Compliance\Application\DTO\Dashboard\BucketCountsDTO;
use App\Compliance\Application\DTO\Dashboard\DeptBreakdownDTO;
use App\Compliance\Application\DTO\Dashboard\PersonRowDTOTransformer;
use App\Compliance\Application\DTO\Dashboard\RequirementBreakdownDTO;
use App\Compliance\Application\DTO\Dashboard\RequirementGroupDTO;
use App\Compliance\Application\Service\ComplianceDashboardScope;
use App\Compliance\Domain\Repository\ObligationProjectionRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Картина: KPI + разрезы отдел/требование — всё по (человек × требование), т.е. единица счёта — требование
 * человека в своём бакете (у одного человека несколько требований). Считает по популяции фильтра БЕЗ
 * statusBucket/onlyProblems (те — drill-down списка), чтобы KPI показывал полную раскладку. PHP над набором.
 * Источник обязанностей — read-модель проекции ({@see ObligationProjectionRepositoryInterface}), не агрегат.
 */
final readonly class GetComplianceOverviewQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ObligationProjectionRepositoryInterface $repository,
        private PersonRowDTOTransformer $transformer,
        private ComplianceDashboardScope $scope,
    ) {
    }

    public function __invoke(GetComplianceOverviewQuery $query): GetComplianceOverviewQueryResult
    {
        $filter = $query->filter;
        $now = new \DateTimeImmutable();

        $scopeIds = $this->scope->restrictProfileIds($filter->profileIds, $filter->positionIds, $filter->q);
        $rows = $this->repository->findForProfiles($scopeIds);
        $profilesById = [] === $rows ? [] : $this->scope->profilesById($rows);

        $kpi = new BucketCountsDTO();
        /** @var array<string, DeptBreakdownDTO> $depts */
        $depts = [];
        /** @var array<string, RequirementBreakdownDTO> $reqs */
        $reqs = [];

        foreach ($this->scope->groupByProfile($rows) as $profileId => $obligationRows) {
            $profile = $profilesById[$profileId] ?? null;
            if (null === $profile || ($filter->departmentIds->count() > 0 && !in_array($profile->departmentId, $filter->departmentIds->getList(), true))) {
                continue;
            }
            $row = $this->transformer->fromRows($obligationRows, $profile, $now, $filter->type);
            if ([] === $row->groups) {
                continue;
            }

            // Считаем по ПОЗИЦИЯМ: каждая обязанность — единица в своём бакете (просроченное за невыданным в
            // одной карточке не прячется под worst группы). KPI и отдел — сумма позиций человека; разрез по
            // требованию — позиции группы. `peopleCount` — число людей (отдел: +1 на человека; требование: +1 на носителя).
            $dept = $this->department($depts, $profile->departmentTitle);
            ++$dept->peopleCount;
            $kpi->addAll($row->material);
            $kpi->addAll($row->nonMaterial);
            $dept->counts->addAll($row->material);
            $dept->counts->addAll($row->nonMaterial);
            foreach ($row->groups as $group) {
                $req = $this->requirement($reqs, $group);
                $req->counts->addAll($group->counts);
                ++$req->peopleCount;
            }
        }

        return new GetComplianceOverviewQueryResult($kpi, $this->sorted($depts), $this->sorted($reqs));
    }

    /**
     * @param array<string, DeptBreakdownDTO> $depts
     */
    private function department(array &$depts, string $title): DeptBreakdownDTO
    {
        $key = '' === trim($title) ? '—' : $title;
        if (!isset($depts[$key])) {
            $dto = new DeptBreakdownDTO();
            $dto->title = $key;
            $dto->counts = new BucketCountsDTO();
            $depts[$key] = $dto;
        }

        return $depts[$key];
    }

    /**
     * @param array<string, RequirementBreakdownDTO> $reqs
     */
    private function requirement(array &$reqs, RequirementGroupDTO $group): RequirementBreakdownDTO
    {
        if (!isset($reqs[$group->requirementId])) {
            $dto = new RequirementBreakdownDTO();
            $dto->requirementId = $group->requirementId;
            $dto->name = $group->name;
            $dto->type = $group->type;
            $dto->typeLabel = $group->typeLabel;
            $dto->counts = new BucketCountsDTO();
            $reqs[$group->requirementId] = $dto;
        }

        return $reqs[$group->requirementId];
    }

    /**
     * @template T of DeptBreakdownDTO|RequirementBreakdownDTO
     *
     * @param array<string, T> $items
     *
     * @return list<T>
     */
    private function sorted(array $items): array
    {
        $list = array_values($items);
        usort($list, static fn ($a, $b): int => $b->counts->problems() <=> $a->counts->problems());

        return $list;
    }
}
