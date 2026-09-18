<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\SuggestCounterparties;

use App\Shared\Application\Query\Query;

final readonly class SuggestCounterpartiesQuery extends Query
{
    public function __construct(
        public string $query,
        public int $limit = 10,
    ) {
    }
}
