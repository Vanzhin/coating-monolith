<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\Dashboard;

use App\Compliance\Application\DTO\Dashboard\PersonRowDTO;
use App\Compliance\Application\DTO\Dashboard\PersonRowDTOTransformer;
use App\Compliance\Application\Service\ComplianceDashboardScope;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Type\ComplianceBucket;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

/**
 * Список людей дашборда: owner-скоуп + пред-сужение (сервис), загрузка проекций, бакеты/фильтры/сорт/пагинация
 * — в PHP (масштаб — сотрудники).
 */
final readonly class GetPagedComplianceQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProfileComplianceRepositoryInterface $repository,
        private PersonRowDTOTransformer $transformer,
        private ComplianceDashboardScope $scope,
    ) {
    }

    public function __invoke(GetPagedComplianceQuery $query): GetPagedComplianceQueryResult
    {
        $filter = $query->filter;
        $pager = $filter->pager ?? Pager::fromPage();
        $now = new \DateTimeImmutable();

        $projections = $this->repository->findForDashboard(
            $this->scope->restrictProfileIds($filter->profileIds, $filter->positionIds, $filter->q),
            $filter->departmentIds,
        );
        if ([] === $projections) {
            return new GetPagedComplianceQueryResult([], new Pager($pager->page, $pager->perPage, 0));
        }

        $profilesById = $this->scope->profilesById($projections);

        $rows = [];
        foreach ($projections as $projection) {
            $profile = $profilesById[$projection->getProfileId()] ?? null;
            if (null === $profile) {
                continue; // профиль удалён — строку не показываем
            }
            $row = $this->transformer->fromEntity($projection, $profile, $now, $filter->type);
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
            $rows[] = $row;
        }

        usort($rows, static function (PersonRowDTO $a, PersonRowDTO $b): int {
            $severity = ComplianceBucket::from($b->worstBucket)->severity() <=> ComplianceBucket::from($a->worstBucket)->severity();

            return 0 !== $severity ? $severity : strcmp($a->fullName, $b->fullName);
        });

        $total = \count($rows);
        $page = \array_slice($rows, $pager->getOffset(), $pager->getLimit());

        return new GetPagedComplianceQueryResult(array_values($page), new Pager($pager->page, $pager->perPage, $total));
    }

    /** Сколько у человека позиций в бакете (материальные + нематериальные) — единица фильтра/счёта. */
    private function bucketCount(PersonRowDTO $row, ComplianceBucket $bucket): int
    {
        return $row->material->count($bucket) + $row->nonMaterial->count($bucket);
    }
}
