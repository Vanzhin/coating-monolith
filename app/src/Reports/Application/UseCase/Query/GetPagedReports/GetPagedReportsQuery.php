<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetPagedReports;

use App\Reports\Domain\Repository\ReportsFilter;
use App\Shared\Application\Query\Query;

final readonly class GetPagedReportsQuery extends Query
{
    public function __construct(public ReportsFilter $filter)
    {
    }
}
