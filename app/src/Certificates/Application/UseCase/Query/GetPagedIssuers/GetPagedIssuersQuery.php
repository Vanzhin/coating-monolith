<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Query\GetPagedIssuers;

use App\Certificates\Domain\Repository\IssuersFilter;
use App\Shared\Application\Query\Query;

final readonly class GetPagedIssuersQuery extends Query
{
    public function __construct(public IssuersFilter $filter)
    {
    }
}
