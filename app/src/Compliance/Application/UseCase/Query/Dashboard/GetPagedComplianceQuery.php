<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\Dashboard;

use App\Shared\Application\Query\Query;

readonly class GetPagedComplianceQuery extends Query
{
    public function __construct(public ComplianceDashboardFilter $filter)
    {
    }
}
