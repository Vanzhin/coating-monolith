<?php

declare(strict_types=1);

namespace App\Documents\Infrastructure\Controller\Api;

use App\Documents\Application\UseCase\Query\GetDocumentCountByCategory\GetDocumentCountByCategoryQuery;
use App\Documents\Domain\Repository\DocumentFilter;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/document/count-by-category', name: 'app_api_document_count_by_category', methods: ['GET'])]
class GetDocumentCountByCategoryAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $search = $request->query->get('search');

        $filter = new DocumentFilter(
            $search,
            null,
            null,
        );
        $query = new GetDocumentCountByCategoryQuery($filter);
        $result = $this->queryBus->execute($query);

        return new JsonResponse($result, Response::HTTP_OK);
    }
}