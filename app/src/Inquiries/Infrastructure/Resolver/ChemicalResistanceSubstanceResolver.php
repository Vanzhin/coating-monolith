<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Resolver;

use App\ChemicalResistance\Application\DTO\SubstanceDTO;
use App\ChemicalResistance\Application\UseCase\Query\SubstanceAutocomplete\SubstanceAutocompleteQuery;
use App\Inquiries\Application\Port\SubstanceResolver;
use App\Shared\Application\Query\QueryBusInterface;

/**
 * Резолвит вещество через published-запрос ChemicalResistance (autocomplete: fuzzy по
 * canonical_name/cas/aliases), берёт лучший (первый) матч. Стык только через query-шину + DTO —
 * без доменного и без конкретного класса хендлера ChemicalResistance.
 */
final readonly class ChemicalResistanceSubstanceResolver implements SubstanceResolver
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    public function resolve(string $name): ?string
    {
        $name = trim($name);
        if ('' === $name) {
            return null;
        }

        /** @var list<SubstanceDTO> $matches */
        $matches = $this->queryBus->execute(new SubstanceAutocompleteQuery($name, 1));

        return $matches[0]->id ?? null;
    }
}
