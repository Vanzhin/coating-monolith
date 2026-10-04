<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Requirements;

use App\Compliance\Application\UseCase\Query\ListRequirements\ListRequirementsQuery;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Список требований. Просмотр открыт всем авторизованным; кнопки правки — под canEdit.
 */
#[Route(path: '/cabinet/compliance/requirements', name: 'app_cabinet_compliance_requirements_list', methods: ['GET'])]
final class ListAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $page = $request->query->get('page') ? (int) $request->query->get('page') : null;
        $result = $this->queryBus->execute(new ListRequirementsQuery(Pager::fromPage($page, 50)));

        return $this->render('admin/compliance/requirements/index.html.twig', compact('result'));
    }
}
