<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Coating;

use App\ChemicalResistance\Application\UseCase\Query\ListCoatingAssessments\ListCoatingAssessmentsQuery;
use App\ChemicalResistance\Application\UseCase\Query\ListCoatingAssessments\ListCoatingAssessmentsQueryHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

#[Route(
    path: '/cabinet/coatings/{coatingId}/chem-resistance/partial',
    name: 'app_cabinet_coating_chem_resistance_partial',
    requirements: ['coatingId' => '[0-9a-f-]{36}'],
    methods: ['GET'],
)]
final class AssessmentsPartialAction
{
    public function __construct(
        private ListCoatingAssessmentsQueryHandler $handler,
        private Environment $twig,
    ) {}

    public function __invoke(string $coatingId, Request $req): Response
    {
        $page     = max(1, $req->query->getInt('page', 1));
        $pageSize = max(1, min(2000, $req->query->getInt('pageSize', 50)));
        $search   = $req->query->get('search') ?: null;
        $highlight = $req->query->get('highlight') ?: null;

        $result = ($this->handler)(new ListCoatingAssessmentsQuery(
            coatingId: $coatingId,
            search: $search,
            page: $page,
            pageSize: $pageSize,
            highlightSubstanceId: $highlight,
        ));

        return new Response($this->twig->render(
            'admin/coating/coating/_chem_resistance_rows_only.html.twig',
            ['rows' => $result->rows, 'highlight' => $highlight],
        ));
    }
}
