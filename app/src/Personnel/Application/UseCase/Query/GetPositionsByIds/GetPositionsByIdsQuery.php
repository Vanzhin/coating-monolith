<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetPositionsByIds;

use App\Shared\Application\Query\Query;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

readonly class GetPositionsByIdsQuery extends Query
{
    public function __construct(public StringCollection $ids)
    {
    }
}
