<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetReportRenderData;

use App\Shared\Application\Query\Query;

final readonly class GetReportRenderDataQuery extends Query
{
    public function __construct(public string $reportId)
    {
    }
}
