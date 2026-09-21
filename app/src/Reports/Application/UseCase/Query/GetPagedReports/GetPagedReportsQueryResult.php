<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetPagedReports;

use App\Reports\Application\DTO\Reports\ReportListItemDTO;
use App\Shared\Domain\Repository\Pager;

final readonly class GetPagedReportsQueryResult
{
    /**
     * @param list<ReportListItemDTO> $reports
     */
    public function __construct(
        public array $reports,
        public Pager $pager,
    ) {
    }
}
