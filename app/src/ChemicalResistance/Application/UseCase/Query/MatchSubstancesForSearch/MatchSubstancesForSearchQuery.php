<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\MatchSubstancesForSearch;

final readonly class MatchSubstancesForSearchQuery
{
    public function __construct(
        /** @var list<string> $coatingIds — UUID strings (rfc4122) */
        public array $coatingIds,
        /** @var list<string> $searchWords — raw words; normalized inside the handler */
        public array $searchWords,
    ) {
    }
}
