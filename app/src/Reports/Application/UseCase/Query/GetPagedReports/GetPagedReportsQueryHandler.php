<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetPagedReports;

use App\Reports\Application\DTO\Reports\ReportListItemDTOTransformer;
use App\Reports\Application\Service\AccessControl\ReportAccessControl;
use App\Reports\Domain\Aggregate\Report\Report;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Reports\Domain\Repository\ReportsFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use App\Users\Application\UseCase\Query\GetUsersByIds\GetUsersByIdsQuery;
use App\Users\Application\UseCase\Query\GetUsersByIds\GetUsersByIdsQueryResult;

/**
 * Список отчётов, owner-scoped: не-админ видит только свои (ownerIds форсится актором), админ — все
 * либо выбранных владельцев. Владение решается тут, в Application, а не в адаптере/репозитории.
 * Лейблы владельцев (email) на карточках дотягиваем батчем — только админу.
 */
final readonly class GetPagedReportsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ReportRepositoryInterface $repository,
        private ReportListItemDTOTransformer $transformer,
        private ReportAccessControl $access,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(GetPagedReportsQuery $query): GetPagedReportsQueryResult
    {
        $pager = $query->filter->pager ?? Pager::fromPage();
        // Не-админа принудительно запираем на свои отчёты; выбранные им ownerIds игнорируем.
        $ownerIds = $this->access->isManager()
            ? $query->filter->ownerIds
            : new StringCollection($this->access->currentUserId());

        $filter = new ReportsFilter(
            pager: $pager,
            ownerIds: $ownerIds,
            customerIds: $query->filter->customerIds,
            contractorIds: $query->filter->contractorIds,
            projectIds: $query->filter->projectIds,
            type: $query->filter->type,
            status: $query->filter->status,
            search: $query->filter->search,
            sort: $query->filter->sort,
        );
        $paginator = $this->repository->findByFilter($filter);

        return new GetPagedReportsQueryResult(
            $this->transformer->fromEntityList($paginator->items, $this->resolveOwnerLabels($paginator->items)),
            new Pager($pager->page, $pager->perPage, $paginator->total),
        );
    }

    /**
     * ownerId → email. Только админу (не-админ и так видит свои — владельца на карточке не показываем).
     *
     * @param list<Report> $reports
     *
     * @return array<string, string>
     */
    private function resolveOwnerLabels(array $reports): array
    {
        if (!$this->access->isManager() || [] === $reports) {
            return [];
        }

        $ids = array_values(array_unique(array_map(static fn (Report $r): string => $r->getOwnerId(), $reports)));

        /** @var GetUsersByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetUsersByIdsQuery(new StringCollection(...$ids)));

        $labels = [];
        foreach ($result->users as $user) {
            $labels[$user->id] = $user->title;
        }

        return $labels;
    }
}
