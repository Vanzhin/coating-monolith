<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\Dashboard;

use App\Compliance\Application\DTO\Dashboard\BucketCountsDTO;
use App\Compliance\Application\DTO\Dashboard\DeptBreakdownDTO;
use App\Compliance\Application\DTO\Dashboard\RequirementBreakdownDTO;

final readonly class GetComplianceOverviewQueryResult
{
    /**
     * @param list<DeptBreakdownDTO>        $departments
     * @param list<RequirementBreakdownDTO> $requirements
     */
    public function __construct(
        public BucketCountsDTO $kpi,
        public array $departments,
        public array $requirements,
    ) {
    }
}
