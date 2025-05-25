<?php

declare(strict_types=1);

namespace App\Documents\Infrastructure\Controller\Api;

use App\Documents\Application\UseCase\Query\GetPagedDocuments\GetPagedDocumentsQuery;
use App\Documents\Domain\Repository\DocumentFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/document/list', name: 'app_api_document_list', methods: ['GET'])]
class GetPagedDocumentsAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $search = $request->query->get('search');
        $page = $request->query->get('page') ? (int)$request->query->get('page') : null;
        $limit = $request->query->get('limit') ? (int)$request->query->get('limit') : null;
        $filter = new DocumentFilter(
            $search,
            null,
            null,
            Pager::fromPage($page, $limit)
        );
        $query = new GetPagedDocumentsQuery($filter);
        $result = $this->queryBus->execute($query);

        return new JsonResponse($result, Response::HTTP_CREATED);
    }
}