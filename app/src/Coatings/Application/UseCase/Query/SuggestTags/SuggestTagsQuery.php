<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SuggestTags;

use App\Shared\Application\Query\Query;

final readonly class SuggestTagsQuery extends Query
{
    public function __construct(
        public string $query,
        public ?string $type = null,
        public int $limit = 10,
    ) {
    }
}
