<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetPagedReports;

use App\Reports\Application\DTO\Reports\ReportListItemDTOTransformer;
use App\Reports\Application\Service\AccessControl\ReportAccessControl;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Reports\Domain\Repository\ReportsFilter;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

/**
 * Список отчётов, owner-scoped: не-админ видит только свои (ownerId форсится актором), админ — все.
 * Владение решается тут, в Application, а не в адаптере/репозитории.
 */
final readonly class GetPagedReportsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ReportRepositoryInterface $repository,
        private ReportListItemDTOTransformer $transformer,
        private ReportAccessControl $access,
    ) {
    }

    public function __invoke(GetPagedReportsQuery $query): GetPagedReportsQueryResult
    {
        $pager = $query->filter->pager ?? Pager::fromPage();
        $ownerId = $this->access->isManager() ? $query->filter->ownerId : $this->access->currentUserId();

        $filter = new ReportsFilter($pager, $ownerId, $query->filter->status, $query->filter->search);
        $paginator = $this->repository->findByFilter($filter);

        return new GetPagedReportsQueryResult(
            $this->transformer->fromEntityList($paginator->items),
            new Pager($pager->page, $pager->perPage, $paginator->total),
        );
    }
}
