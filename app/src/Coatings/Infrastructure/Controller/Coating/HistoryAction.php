<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQuery;
use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQueryResult;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Shared\Application\Audit\Query\GetEntityAuditLog\GetEntityAuditLogQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Вкладка «История» покрытия — лента изменений из журнала аудита. Доступ только
 * управляющему (админ/система) — гейт в GetEntityAuditLogQueryHandler через
 * AuditAccessControl, тонкий контроллер его не проверяет.
 */
#[Route(path: '/cabinet/coating/coating/{id}/history', name: 'app_cabinet_coating_coating_history', methods: ['GET'])]
class HistoryAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        /** @var GetCoatingQueryResult $coating */
        $coating = $this->queryBus->execute(new GetCoatingQuery($id));
        if (!$coating->coatingDTO) {
            $this->addFlash('coating_not_found_error', sprintf('Покрытие с идентификатором "%s" не найдено.', $id));

            return $this->redirectToRoute('app_cabinet_coating_coating_list');
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $log = $this->queryBus->execute(new GetEntityAuditLogQuery(Coating::class, $id, $page));

        return $this->render('admin/coating/coating/history.html.twig', [
            'coating' => $coating->coatingDTO,
            'log' => $log,
        ]);
    }
}
