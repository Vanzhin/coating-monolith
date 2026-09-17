<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\GetUsersByIds;

use App\Shared\Application\Query\Query;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

readonly class GetUsersByIdsQuery extends Query
{
    public function __construct(public StringCollection $ids)
    {
    }
}
