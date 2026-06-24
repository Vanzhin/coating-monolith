<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingsByIds;

use App\Shared\Application\Query\Query;

readonly class GetCoatingsByIdsQuery extends Query
{
    /** @param list<string> $ids */
    public function __construct(public array $ids)
    {
    }
}
