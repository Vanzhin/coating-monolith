<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\Acts;

use App\Compliance\Application\DTO\Acts\ComplianceActRowDTO;
use App\Shared\Domain\Repository\Pager;

final readonly class GetComplianceActsQueryResult
{
    /**
     * @param list<ComplianceActRowDTO> $acts
     */
    public function __construct(public array $acts, public Pager $pager)
    {
    }
}
