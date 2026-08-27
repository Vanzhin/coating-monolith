<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Controller\Issuer;

use App\Certificates\Application\UseCase\Query\GetPagedIssuers\GetPagedIssuersQuery;
use App\Certificates\Domain\Repository\IssuersFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/certificate/issuer',
    name: 'app_cabinet_certificate_issuer_list',
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

        $filter = new IssuersFilter(pager: Pager::fromPage($page, 50), title: $search);
        $result = $this->queryBus->execute(new GetPagedIssuersQuery($filter));

        return $this->render('admin/certificate/issuer/index.html.twig', compact('result', 'search'));
    }
}
