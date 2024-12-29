<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Controller;

use App\Proposals\Application\UseCase\Query\GetPagedGeneralProposalInfo\GetPagedGeneralProposalInfoQuery;
use App\Proposals\Domain\Repository\GeneralProposalInfoFilter;
use App\Proposals\Infrastructure\Adapter\CoatingsAdapter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/proposals/list', name: 'app_cabinet_proposals_general_proposal_list', methods: ['GET'])]
class ListAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CoatingsAdapter   $coatingsAdapter
    )
    {
    }

    public function __invoke(Request $request): Response
    {
        $search = $request->query->get('search');
        $page = $request->query->get('page') ? (int)$request->query->get('page') : null;
        $limit = $request->query->get('limit') ? (int)$request->query->get('limit') : null;
        $query = new GetPagedGeneralProposalInfoQuery(new GeneralProposalInfoFilter($this->getUser()->getUlid(), $search, Pager::fromPage($page, $limit)));
        $result = $this->queryBus->execute($query);
        $coatings = $this->coatingsAdapter->getPagedCoatings();
        return $this->render('cabinet/proposal/index.html.twig', compact('result', 'coatings'));
    }
}