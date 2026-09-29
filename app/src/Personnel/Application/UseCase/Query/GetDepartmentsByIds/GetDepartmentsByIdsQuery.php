<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetDepartmentsByIds;

use App\Shared\Application\Query\Query;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

readonly class GetDepartmentsByIdsQuery extends Query
{
    public function __construct(public StringCollection $ids)
    {
    }
}
