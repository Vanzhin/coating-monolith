<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SuggestColors;

use App\Shared\Application\Query\Query;

final readonly class SuggestColorsQuery extends Query
{
    public function __construct(
        public string $query,
        public int $limit = 10,
    ) {
    }
}
