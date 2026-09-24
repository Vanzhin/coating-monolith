<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use App\Reports\Application\UseCase\Query\GetPagedReports\GetPagedReportsQuery;
use App\Reports\Domain\Aggregate\Report\ReportStatus;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Repository\ReportsFilter;
use App\Reports\Domain\Repository\ReportsSort;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

/**
 * Список отчётов текущего пользователя (админ — все). Поиск + фасеты (владелец/заказчик/подрядчик/
 * проект) + «загрузить ещё» (partial=1 отдаёт только пачку карточек следующей страницы).
 * Owner-scoping — в хендлере, не тут. Фасеты сущностей рисуются в шаблоне только админу.
 */
#[Route(path: '/cabinet/report', name: 'app_cabinet_report_list', methods: ['GET'])]
final class ListAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly QueryParams $queryParams,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = $request->query->get('page') ? (int) $request->query->get('page') : null;
        $search = trim((string) $request->query->get('search', '')) ?: null;
        $status = ReportStatus::tryFrom((string) $request->query->get('status', ''));
        $type = ReportType::tryFrom((string) $request->query->get('type', ''));
        $sortRaw = $request->query->get('sort');
        $sort = (is_string($sortRaw) ? ReportsSort::tryFrom($sortRaw) : null) ?? ReportsSort::DEFAULT;

        // owner — ULID (User.id); заказчик/подрядчик/проект — UUID. Битые id тихо отсеиваем.
        $ownerIds = $this->queryParams->stringCollection($request, 'ownerIds', [Ulid::class, 'isValid'], unique: true);
        $customerIds = $this->queryParams->stringCollection($request, 'customerIds', [Uuid::class, 'isValid'], unique: true);
        $contractorIds = $this->queryParams->stringCollection($request, 'contractorIds', [Uuid::class, 'isValid'], unique: true);
        $projectIds = $this->queryParams->stringCollection($request, 'projectIds', [Uuid::class, 'isValid'], unique: true);

        $filter = new ReportsFilter(
            pager: Pager::fromPage($page, 20),
            ownerIds: $ownerIds,
            customerIds: $customerIds,
            contractorIds: $contractorIds,
            projectIds: $projectIds,
            type: $type,
            status: $status,
            search: $search,
            sort: $sort,
        );
        $result = $this->queryBus->execute(new GetPagedReportsQuery($filter));

        if ($request->query->getBoolean('partial')) {
            return $this->render('cabinet/report/_report_cards_batch.html.twig', ['reports' => $result->reports]);
        }

        return $this->render('cabinet/report/index.html.twig', [
            'result' => $result,
            'search' => $search,
            'status' => $status?->value,
            'type' => $type?->value,
            'types' => ReportType::cases(),
            'sort' => $sort,
            'sortOptions' => ReportsSort::cases(),
            'ownerIds' => $ownerIds->getList(),
            'customerIds' => $customerIds->getList(),
            'contractorIds' => $contractorIds->getList(),
            'projectIds' => $projectIds->getList(),
        ]);
    }
}
