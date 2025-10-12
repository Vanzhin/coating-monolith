<?php
declare(strict_types=1);

namespace App\Proposals\Domain\Service;

class CoatingsQueryResult
{
    public function __construct(
        public readonly array $coatings,
        public readonly int $totalCount,
        public readonly int $page,
        public readonly int $limit
    ) {
    }
}
