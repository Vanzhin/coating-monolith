<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetResistantCoatings;

/**
 * Обратный поиск химстойкости: какие покрытия оценены по веществу.
 * includeAll=false — только «стойкие» (Grade::isSuitable() = R|LR); true — все грейды
 * (для админ-управления).
 */
final readonly class GetResistantCoatingsQuery
{
    public function __construct(
        public string $substanceId,
        public bool $includeAll = false,
    ) {
    }
}
