<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\Dashboard;

use App\Compliance\Application\DTO\Dashboard\BucketCountsDTO;
use App\Compliance\Application\DTO\Dashboard\DeptBreakdownDTO;
use App\Compliance\Application\DTO\Dashboard\PersonRowDTOTransformer;
use App\Compliance\Application\DTO\Dashboard\RequirementBreakdownDTO;
use App\Compliance\Application\DTO\Dashboard\RequirementGroupDTO;
use App\Compliance\Application\ReadModel\ComplianceBucket;
use App\Compliance\Application\Service\ComplianceDashboardScope;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Картина: KPI (люди по худшему бакету) + разрезы отдел/требование. Считает по популяции фильтра БЕЗ
 * statusBucket/onlyProblems (те — drill-down списка), чтобы KPI показывал полную раскладку. PHP над набором.
 */
final readonly class GetComplianceOverviewQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProfileComplianceRepositoryInterface $repository,
        private PersonRowDTOTransformer $transformer,
        private ComplianceDashboardScope $scope,
    ) {
    }

    public function __invoke(GetComplianceOverviewQuery $query): GetComplianceOverviewQueryResult
    {
        $filter = $query->filter;
        $now = new \DateTimeImmutable();

        $projections = $this->repository->findForDashboard(
            $this->scope->restrictProfileIds($filter),
            $filter->departmentIds,
        );
        $profilesById = [] === $projections ? [] : $this->scope->profilesById($projections);

        $kpi = new BucketCountsDTO();
        /** @var array<string, DeptBreakdownDTO> $depts */
        $depts = [];
        /** @var array<string, RequirementBreakdownDTO> $reqs */
        $reqs = [];

        foreach ($projections as $projection) {
            $profile = $profilesById[$projection->getProfileId()] ?? null;
            if (null === $profile) {
                continue;
            }
            $row = $this->transformer->fromEntity($projection, $profile, $now, $filter->type);
            if ([] === $row->groups) {
                continue;
            }
            $worst = ComplianceBucket::from($row->worstBucket);

            $kpi->add($worst);
            $this->department($depts, $profile->departmentTitle)->counts->add($worst);
            foreach ($row->groups as $group) {
                $this->requirement($reqs, $group)->counts->add($this->worstOfGroup($group));
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

    private function worstOfGroup(RequirementGroupDTO $group): ComplianceBucket
    {
        $worst = ComplianceBucket::Ok;
        foreach ($group->rows as $row) {
            $worst = ComplianceBucket::worseOf($worst, ComplianceBucket::from($row->bucket));
        }

        return $worst;
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
