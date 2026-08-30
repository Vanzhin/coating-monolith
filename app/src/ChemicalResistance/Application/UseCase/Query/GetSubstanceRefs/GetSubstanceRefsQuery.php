<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetSubstanceRefs;

use App\Shared\Domain\Aggregate\Collection\StringCollection;

/**
 * Резолв веществ по списку id → id + каноническое имя (для чипов и разбивки вердикта).
 */
final readonly class GetSubstanceRefsQuery
{
    public function __construct(
        public StringCollection $ids,
    ) {
    }
}
