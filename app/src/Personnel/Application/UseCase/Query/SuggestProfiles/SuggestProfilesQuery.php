<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\SuggestProfiles;

use App\Shared\Application\Query\Query;

final readonly class SuggestProfilesQuery extends Query
{
    public function __construct(
        public string $query,
        public int $limit = 10,
        public ?string $organizationId = null,
    ) {
    }
}
