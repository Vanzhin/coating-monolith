<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetPagedNotes;

use App\ChemicalResistance\Domain\Repository\NoteRepository;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

class GetPagedNotesQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly NoteRepository $noteRepository,
    ) {}

    public function __invoke(GetPagedNotesQuery $query): GetPagedNotesQueryResult
    {
        $paginator = $this->noteRepository->findByFilter($query->filter);

        $pager = new Pager(
            $query->filter->pager?->page ?? Pager::DEFAULT_PAGE,
            $query->filter->pager?->perPage ?? Pager::DEFAULT_LIMIT,
            $paginator->total,
        );

        return new GetPagedNotesQueryResult($paginator->items, $pager);
    }
}
