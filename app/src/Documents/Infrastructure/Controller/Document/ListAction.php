<?php

declare(strict_types=1);

namespace App\Documents\Infrastructure\Controller\Document;

use App\Documents\Application\UseCase\Query\GetDocumentCountByCategory\GetDocumentCountByCategoryQuery;
use App\Documents\Application\UseCase\Query\GetPagedDocuments\GetPagedDocumentsQuery;
use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentCategoryType;
use App\Documents\Domain\Repository\DocumentFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/document/list', name: 'app_cabinet_document_list', methods: ['GET'])]
class ListAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $inputData = $request->query->all();
        $result = null;
        $categoryTypes = DocumentCategoryType::array();
        $countByCategory = null;
        if (!empty($inputData['search'])) {
            $page = $request->query->get('page') ? (int)$request->query->get('page') : null;
            $limit = $request->query->get('limit') ? (int)$request->query->get('limit') : null;
            $filter = new DocumentFilter(
                $inputData['search'],
                null,
                null,
                Pager::fromPage($page, $limit)

            );
            foreach ($inputData['categories'] ?? [] as $category) {
                $category = DocumentCategoryType::fromName($category);
                if ($category) {
                    $filter->addCategoryType($category);
                }
            }
            $query = new GetPagedDocumentsQuery($filter);
            $result = $this->queryBus->execute($query);
            // получаю счетчик по всем категориям
            $filter->setCategoryTypes([]);

            $countByCategory = $this->queryBus->execute(new GetDocumentCountByCategoryQuery($filter));
        }

        return $this->render(
            'cabinet/document/index.html.twig',
            compact('result', 'inputData', 'categoryTypes', 'countByCategory')
        );
    }
}