<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetPagedProjects;

use App\Reports\Application\DTO\Projects\ProjectDTO;
use App\Shared\Domain\Repository\Pager;

final readonly class GetPagedProjectsQueryResult
{
    /**
     * @param list<ProjectDTO> $projects
     */
    public function __construct(
        public array $projects,
        public Pager $pager,
    ) {
    }
}
