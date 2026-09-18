<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetReport;

use App\Reports\Application\DTO\Reports\ReportDTO;

final readonly class GetReportQueryResult
{
    public function __construct(public ?ReportDTO $report)
    {
    }
}
