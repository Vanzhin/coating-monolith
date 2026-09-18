<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\SuggestProjects;

use App\Reports\Application\DTO\Projects\ProjectDTO;

final readonly class SuggestProjectsQueryResult
{
    /**
     * @param list<ProjectDTO> $projects
     */
    public function __construct(public array $projects)
    {
    }
}
