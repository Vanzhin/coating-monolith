<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\SuggestDepartments;

use App\Shared\Application\Query\Query;

final readonly class SuggestDepartmentsQuery extends Query
{
    public function __construct(
        public string $query,
        public int $limit = 10,
    ) {
    }
}
