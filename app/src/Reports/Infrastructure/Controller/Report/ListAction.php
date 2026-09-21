<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use App\Reports\Application\UseCase\Query\GetPagedReports\GetPagedReportsQuery;
use App\Reports\Domain\Aggregate\Report\ReportStatus;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Repository\ReportsFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Список отчётов текущего пользователя (админ — все). Поиск + «загрузить ещё» (partial=1 отдаёт
 * только пачку карточек следующей страницы). Owner-scoping — в хендлере, не тут.
 */
#[Route(path: '/cabinet/report', name: 'app_cabinet_report_list', methods: ['GET'])]
final class ListAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $page = $request->query->get('page') ? (int) $request->query->get('page') : null;
        $search = trim((string) $request->query->get('search', '')) ?: null;
        $status = ReportStatus::tryFrom((string) $request->query->get('status', ''));

        $filter = new ReportsFilter(pager: Pager::fromPage($page, 20), status: $status, search: $search);
        $result = $this->queryBus->execute(new GetPagedReportsQuery($filter));

        if ($request->query->getBoolean('partial')) {
            return $this->render('cabinet/report/_report_cards_batch.html.twig', ['reports' => $result->reports]);
        }

        return $this->render('cabinet/report/index.html.twig', [
            'result' => $result,
            'search' => $search,
            'status' => $status?->value,
            'types' => ReportType::cases(),
        ]);
    }
}
