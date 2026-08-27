<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Api\Note;

use App\ChemicalResistance\Application\UseCase\Query\GetPagedNotes\GetPagedNotesQuery;
use App\ChemicalResistance\Application\UseCase\Query\GetPagedNotes\GetPagedNotesQueryHandler;
use App\ChemicalResistance\Domain\Repository\NotesFilter;
use App\Shared\Domain\Repository\Pager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/chemical-resistance/note/autocomplete', name: 'app_api_chemical_resistance_note_autocomplete', methods: ['GET'])]
class AutocompleteAction
{
    public function __construct(private readonly GetPagedNotesQueryHandler $handler)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        $result = ($this->handler)(new GetPagedNotesQuery(
            new NotesFilter('' !== $q ? $q : null, Pager::fromPage(1, 10)),
        ));

        return new JsonResponse(array_map(
            fn ($n) => ['id' => $n->id, 'title' => $n->title, 'description' => $n->description],
            $result->notes,
        ));
    }
}
