<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Resolver;

use App\ChemicalResistance\Application\DTO\SubstanceDTO;
use App\ChemicalResistance\Application\UseCase\Query\SubstanceAutocomplete\SubstanceAutocompleteQuery;
use App\ChemicalResistance\Application\UseCase\Query\SubstanceAutocomplete\SubstanceAutocompleteQueryHandler;
use App\Inquiries\Application\Port\SubstanceResolver;

/**
 * Резолвит вещество через published Application-запрос ChemicalResistance (autocomplete:
 * fuzzy по canonical_name/cas/aliases). Берёт лучший (первый) матч. Это единственное место
 * стыка Inquiries с ChemicalResistance — только через его запрос + DTO, без домена.
 */
final readonly class ChemicalResistanceSubstanceResolver implements SubstanceResolver
{
    public function __construct(private SubstanceAutocompleteQueryHandler $autocomplete)
    {
    }

    public function resolve(string $name): ?string
    {
        $name = trim($name);
        if ('' === $name) {
            return null;
        }

        /** @var list<SubstanceDTO> $matches */
        $matches = ($this->autocomplete)(new SubstanceAutocompleteQuery($name, 1));

        return $matches[0]->id ?? null;
    }
}
