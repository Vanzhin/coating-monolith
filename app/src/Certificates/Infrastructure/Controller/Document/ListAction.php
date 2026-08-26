<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Controller\Document;

use App\Certificates\Application\UseCase\Query\GetPagedDocuments\GetPagedDocumentsQuery;
use App\Certificates\Application\UseCase\Query\GetPagedIssuers\GetPagedIssuersQuery;
use App\Certificates\Domain\Repository\IssuersFilter;
use App\Certificates\Infrastructure\Mapper\DocumentListRequestMapper;
use App\Certificates\Infrastructure\View\DocumentListViewFactory;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/certificate/document',
    name: 'app_cabinet_certificate_document_list',
    methods: ['GET'],
)]
#[IsGranted('ROLE_ADMIN')]
final class ListAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly DocumentListRequestMapper $requestMapper,
        private readonly DocumentListViewFactory $viewFactory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $filter = $this->requestMapper->filterFromRequest($request);
        $result = $this->queryBus->execute(new GetPagedDocumentsQuery($filter));

        // Догрузка бесконечной ленты — отдаём только батч карточек.
        if ($request->query->getBoolean('partial')) {
            return $this->render('admin/certificate/document/_list_cards.html.twig', [
                'documents' => $result->documents,
                'canEdit' => $this->isGranted('ROLE_ADMIN'),
            ]);
        }

        $issuers = $this->queryBus->execute(new GetPagedIssuersQuery(new IssuersFilter(pager: Pager::fromPage(1, 1000))));

        $view = $this->viewFactory->build($request, $result, $issuers->issuers);
        $view['canEdit'] = $this->isGranted('ROLE_ADMIN');

        return $this->render('admin/certificate/document/index.html.twig', $view);
    }
}
