<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Position;

use App\Personnel\Application\UseCase\Query\GetPagedPositions\GetPagedPositionsQuery;
use App\Personnel\Domain\Repository\PositionsFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Просмотр справочника открыт всем авторизованным (PersonnelAccessControl: read не гейтится).
 * Кнопки создания/правки/удаления скрыты в шаблоне под canEdit — управление гейтит хендлер.
 */
#[Route(
    path: '/cabinet/personnel/position',
    name: 'app_cabinet_personnel_position_list',
    methods: ['GET'],
)]
final class ListAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $page = $request->query->get('page') ? (int) $request->query->get('page') : null;
        $search = trim((string) $request->query->get('search', '')) ?: null;

        $filter = new PositionsFilter(pager: Pager::fromPage($page, 50), title: $search);
        $result = $this->queryBus->execute(new GetPagedPositionsQuery($filter));

        return $this->render('admin/personnel/position/index.html.twig', compact('result', 'search'));
    }
}
