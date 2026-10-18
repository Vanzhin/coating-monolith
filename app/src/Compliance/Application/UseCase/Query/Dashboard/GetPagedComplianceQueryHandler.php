<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\Dashboard;

use App\Compliance\Application\DTO\Dashboard\PersonRowDTO;
use App\Compliance\Application\DTO\Dashboard\PersonRowDTOTransformer;
use App\Compliance\Application\Service\ComplianceDashboardScope;
use App\Compliance\Domain\Repository\ObligationProjectionRepositoryInterface;
use App\Compliance\Domain\Type\ComplianceBucket;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

/**
 * Список людей дашборда: owner-скоуп + пред-сужение (сервис), загрузка строк проекции, бакеты/фильтры/сорт/
 * пагинация — в PHP (масштаб — сотрудники). Источник обязанностей — read-модель проекции
 * ({@see ObligationProjectionRepositoryInterface}), не агрегат.
 */
final readonly class GetPagedComplianceQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ObligationProjectionRepositoryInterface $repository,
        private PersonRowDTOTransformer $transformer,
        private ComplianceDashboardScope $scope,
    ) {
    }

    public function __invoke(GetPagedComplianceQuery $query): GetPagedComplianceQueryResult
    {
        $filter = $query->filter;
        $pager = $filter->pager ?? Pager::fromPage();
        $now = new \DateTimeImmutable();

        $scopeIds = $this->scope->restrictProfileIds($filter->profileIds, $filter->positionIds, $filter->q);
        $rows = $this->repository->findForProfiles($scopeIds);
        if ([] === $rows) {
            return new GetPagedComplianceQueryResult([], new Pager($pager->page, $pager->perPage, 0));
        }

        $profilesById = $this->scope->profilesById($rows);

        $people = [];
        foreach ($this->scope->groupByProfile($rows) as $profileId => $obligationRows) {
            $profile = $profilesById[$profileId] ?? null;
            if (null === $profile || ($filter->departmentIds->count() > 0 && !in_array($profile->departmentId, $filter->departmentIds->getList(), true))) {
                continue; // профиль удалён/не попадает в выбранный отдел — строку не показываем
            }
            $row = $this->transformer->fromRows($obligationRows, $profile, $now, $filter->type);
            if ([] === $row->groups) {
                continue; // фильтр по типу отсёк все обязанности
            }
            $worst = ComplianceBucket::from($row->worstBucket);
            // Фильтр по чипу согласован с его счётом — по ПОЗИЦИЯМ: человек в списке бакета, если у него есть
            // хотя бы одна позиция в этом бакете (не по worst группы/человека — иначе просроченное прячется за
            // невыданным в той же карточке).
            if (null !== $filter->statusBucket && 0 === $this->bucketCount($row, $filter->statusBucket)) {
                continue;
            }
            if ($filter->onlyProblems && 0 === $row->material->problems() + $row->nonMaterial->problems()) {
                continue;
            }
            $people[] = $row;
        }

        usort($people, static function (PersonRowDTO $a, PersonRowDTO $b): int {
            $severity = ComplianceBucket::from($b->worstBucket)->severity() <=> ComplianceBucket::from($a->worstBucket)->severity();

            return 0 !== $severity ? $severity : strcmp($a->fullName, $b->fullName);
        });

        $total = \count($people);
        $page = \array_slice($people, $pager->getOffset(), $pager->getLimit());

        return new GetPagedComplianceQueryResult(array_values($page), new Pager($pager->page, $pager->perPage, $total));
    }

    /** Сколько у человека позиций в бакете (материальные + нематериальные) — единица фильтра/счёта. */
    private function bucketCount(PersonRowDTO $row, ComplianceBucket $bucket): int
    {
        return $row->material->count($bucket) + $row->nonMaterial->count($bucket);
    }
}
