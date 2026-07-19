<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Substance;

use App\ChemicalResistance\Application\UseCase\Query\SubstanceAutocomplete\SubstanceAutocompleteQuery;
use App\ChemicalResistance\Application\UseCase\Query\SubstanceAutocomplete\SubstanceAutocompleteQueryHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/chemical-resistance/substance/autocomplete',
    name: 'app_cabinet_chemical_resistance_substance_autocomplete',
    methods: ['GET'],
)]
final class SubstanceAutocompleteAction extends AbstractController
{
    public function __construct(
        private readonly SubstanceAutocompleteQueryHandler $handler,
    ) {}

    public function __invoke(Request $req): JsonResponse
    {
        $dtos = ($this->handler)(new SubstanceAutocompleteQuery(
            q: $req->query->get('q', ''),
            limit: 10,
        ));

        return new JsonResponse(array_map(
            fn($d) => ['id' => $d->id, 'canonicalName' => $d->canonicalName, 'cas' => $d->cas],
            $dtos,
        ));
    }
}
