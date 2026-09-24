<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Counterparty;

use App\Reports\Application\UseCase\Query\GetPagedCounterparties\GetPagedCounterpartiesQuery;
use App\Reports\Domain\Repository\CounterpartiesFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/reports/counterparty',
    name: 'app_cabinet_reports_counterparty_list',
    methods: ['GET'],
)]
// Справочник контрагентов — управление админское. Список/suggest переиспользуются формой отчёта
// (read не гейтим), поэтому ограничиваем именно эту страницу-управление на контроллере.
#[IsGranted('ROLE_ADMIN')]
final class ListAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $page = $request->query->get('page') ? (int) $request->query->get('page') : null;
        $search = trim((string) $request->query->get('search', '')) ?: null;

        $filter = new CounterpartiesFilter(pager: Pager::fromPage($page, 50), title: $search);
        $result = $this->queryBus->execute(new GetPagedCounterpartiesQuery($filter));

        return $this->render('admin/reports/counterparty/index.html.twig', compact('result', 'search'));
    }
}
