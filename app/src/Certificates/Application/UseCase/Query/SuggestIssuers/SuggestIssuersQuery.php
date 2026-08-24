<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Query\SuggestIssuers;

use App\Shared\Application\Query\Query;

final readonly class SuggestIssuersQuery extends Query
{
    public function __construct(
        public string $query,
        public int $limit = 10,
    ) {
    }
}
