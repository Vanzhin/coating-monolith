<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\ListRequirements;

use App\Compliance\Application\DTO\Requirement\RequirementDTO;
use App\Shared\Domain\Repository\Pager;

final readonly class ListRequirementsQueryResult
{
    /**
     * @param list<RequirementDTO> $requirements
     */
    public function __construct(public array $requirements, public Pager $pager)
    {
    }
}
