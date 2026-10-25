<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\SubstanceAutocomplete;

use App\ChemicalResistance\Application\DTO\SubstanceDTO;
use App\ChemicalResistance\Domain\Repository\SubstanceRepositoryInterface;
use App\ChemicalResistance\Domain\Repository\SubstancesFilter;
use App\Shared\Domain\Repository\Pager;

final class SubstanceAutocompleteQueryHandler
{
    public function __construct(private SubstanceRepositoryInterface $substances)
    {
    }

    /**
     * @return list<SubstanceDTO>
     */
    public function __invoke(SubstanceAutocompleteQuery $q): array
    {
        if ('' === trim($q->q)) {
            return [];
        }

        $filter = new SubstancesFilter(
            search: $q->q,
            pager: Pager::fromPage(1, $q->limit),
        );

        return $this->substances->findByFilter($filter)->items;
    }
}
