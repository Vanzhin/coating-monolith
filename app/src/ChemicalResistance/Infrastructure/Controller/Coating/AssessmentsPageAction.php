<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Coating;

use App\ChemicalResistance\Application\UseCase\Query\ListCoatingAssessments\ListCoatingAssessmentsQuery;
use App\ChemicalResistance\Application\UseCase\Query\ListCoatingAssessments\ListCoatingAssessmentsQueryHandler;
use App\ChemicalResistance\Domain\Repository\NoteRepositoryInterface;
use App\ChemicalResistance\Domain\Repository\NotesFilter;
use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQuery;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/coatings/{coatingId}/chem-resistance',
    name: 'app_cabinet_coating_chem_resistance_edit',
    requirements: ['coatingId' => '[0-9a-f-]{36}'],
    methods: ['GET'],
)]
final class AssessmentsPageAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface                  $queryBus,
        private readonly ListCoatingAssessmentsQueryHandler $assessmentsHandler,
        private readonly NoteRepositoryInterface                     $notes,
    ) {}

    public function __invoke(string $coatingId, Request $req): Response
    {
        $coatingResult = $this->queryBus->execute(new GetCoatingQuery($coatingId));
        if ($coatingResult->coatingDTO === null) {
            throw new AppException(sprintf('Покрытие с идентификатором "%s" не найдено.', $coatingId), 404);
        }
        $coating = $coatingResult->coatingDTO;

        $page     = max(1, $req->query->getInt('page', 1));
        $pageSize = max(1, min(2000, $req->query->getInt('pageSize', 200)));
        $search   = $req->query->get('search') ?: null;

        $assessments = ($this->assessmentsHandler)(new ListCoatingAssessmentsQuery(
            coatingId: $coatingId,
            search: $search,
            page: $page,
            pageSize: $pageSize,
        ));

        $allNotes = $this->notes->findByFilter(new NotesFilter(null, null))->items;

        return $this->render('admin/coating/coating/chem_resistance_edit.html.twig', [
            'coating'     => $coating,
            'assessments' => $assessments,
            'allNotes'    => $allNotes,
            'coatingId'   => $coatingId,
            'search'      => $search,
        ]);
    }
}
