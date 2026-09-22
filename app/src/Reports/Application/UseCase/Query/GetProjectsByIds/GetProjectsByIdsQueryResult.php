<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetProjectsByIds;

use App\Reports\Application\DTO\Projects\ProjectDTO;

final readonly class GetProjectsByIdsQueryResult
{
    /**
     * @param list<ProjectDTO> $projects
     */
    public function __construct(public array $projects)
    {
    }
}
