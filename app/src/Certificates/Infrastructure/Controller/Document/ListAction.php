<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Controller\Document;

use App\Certificates\Application\UseCase\Query\GetPagedDocuments\GetPagedDocumentsQuery;
use App\Certificates\Application\UseCase\Query\GetPagedIssuers\GetPagedIssuersQuery;
use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Repository\DocumentsFilter;
use App\Certificates\Domain\Repository\IssuersFilter;
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
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $page = $request->query->get('page') ? (int) $request->query->get('page') : null;
        $search = trim((string) $request->query->get('q', '')) ?: null;
        $kind = DocumentKind::tryFrom((string) $request->query->get('kind', ''));
        $issuerId = trim((string) $request->query->get('issuerId', '')) ?: null;

        $result = $this->queryBus->execute(new GetPagedDocumentsQuery(new DocumentsFilter(
            pager: Pager::fromPage($page, 30),
            query: $search,
            kind: $kind,
            issuerId: $issuerId,
        )));

        $issuers = $this->queryBus->execute(new GetPagedIssuersQuery(new IssuersFilter(pager: Pager::fromPage(1, 1000))));

        return $this->render('admin/certificate/document/index.html.twig', [
            'result' => $result,
            'issuers' => $issuers->issuers,
            'kinds' => DocumentKind::cases(),
            'search' => $search,
            'kind' => $kind?->value,
            'issuerId' => $issuerId,
        ]);
    }
}
