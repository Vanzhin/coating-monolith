<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\SubstanceAutocomplete;

final readonly class SubstanceAutocompleteQuery
{
    public function __construct(
        public string $q,
        public int $limit = 10,
    ) {
    }
}
