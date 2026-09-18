<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\SuggestProjects;

use App\Shared\Application\Query\Query;

final readonly class SuggestProjectsQuery extends Query
{
    public function __construct(
        public string $query,
        public int $limit = 10,
    ) {
    }
}
