<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\SubstanceAutocomplete;

use App\Shared\Application\Query\Query;

final readonly class SubstanceAutocompleteQuery extends Query
{
    public function __construct(
        public string $q,
        public int $limit = 10,
    ) {
    }
}
