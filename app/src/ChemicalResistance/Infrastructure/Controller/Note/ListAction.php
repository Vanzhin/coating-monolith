<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Note;

use App\ChemicalResistance\Application\UseCase\Query\GetPagedNotes\GetPagedNotesQuery;
use App\ChemicalResistance\Domain\Repository\NotesFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/chemical-resistance/note/list', name: 'app_cabinet_chemical_resistance_note_list', methods: ['GET'])]
class ListAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus) {}

    public function __invoke(Request $request): Response
    {
        $search = $request->query->get('search');
        $page   = $request->query->get('page')  ? (int) $request->query->get('page')  : null;
        $limit  = $request->query->get('limit') ? (int) $request->query->get('limit') : null;

        $result = $this->queryBus->execute(
            new GetPagedNotesQuery(new NotesFilter($search, Pager::fromPage($page, $limit))),
        );

        return $this->render('admin/chemical_resistance/note/index.html.twig', compact('result'));
    }
}
