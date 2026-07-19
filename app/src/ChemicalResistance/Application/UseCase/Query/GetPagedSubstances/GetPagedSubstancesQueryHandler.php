<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetPagedSubstances;

use App\ChemicalResistance\Domain\Repository\SubstanceRepository;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

class GetPagedSubstancesQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly SubstanceRepository $substanceRepository,
    ) {}

    public function __invoke(GetPagedSubstancesQuery $query): GetPagedSubstancesQueryResult
    {
        $paginator = $this->substanceRepository->findByFilter($query->filter);

        $pager = new Pager(
            $query->filter->pager?->page ?? Pager::DEFAULT_PAGE,
            $query->filter->pager?->perPage ?? Pager::DEFAULT_LIMIT,
            $paginator->total,
        );

        return new GetPagedSubstancesQueryResult($paginator->items, $pager);
    }
}
