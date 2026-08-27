<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingSystemsByIds;

use App\Shared\Application\Query\Query;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

readonly class GetCoatingSystemsByIdsQuery extends Query
{
    public function __construct(public StringCollection $ids)
    {
    }
}
