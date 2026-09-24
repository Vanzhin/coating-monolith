<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Project;

use App\Reports\Application\UseCase\Query\GetPagedProjects\GetPagedProjectsQuery;
use App\Reports\Domain\Repository\ProjectsFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/reports/project',
    name: 'app_cabinet_reports_project_list',
    methods: ['GET'],
)]
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

        $filter = new ProjectsFilter(pager: Pager::fromPage($page, 50), title: $search);
        $result = $this->queryBus->execute(new GetPagedProjectsQuery($filter));

        return $this->render('admin/reports/project/index.html.twig', compact('result', 'search'));
    }
}
