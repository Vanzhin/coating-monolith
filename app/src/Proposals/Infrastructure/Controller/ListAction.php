<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Controller;

use App\Coatings\Application\UseCase\Query\GetPagedGeneralProposalInfo\GetPagedGeneralProposalInfoQuery;
use App\Proposals\Domain\Repository\GeneralProposalInfoFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/proposals/list', name: 'app_proposals_general_proposal_list', methods: ['GET'])]
class ListAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    )
    {
    }

    public function __invoke(Request $request): Response
    {
        $search = $request->query->get('search');
        $page = $request->query->get('page') ? (int)$request->query->get('page') : null;
        $limit = $request->query->get('limit') ? (int)$request->query->get('limit') : null;
        $query = new GetPagedGeneralProposalInfoQuery(new GeneralProposalInfoFilter($search, Pager::fromPage($page, $limit)));
        $result = $this->queryBus->execute($query);

        return $this->render('admin/coating/coating/index.html.twig', compact('result'));
    }
}