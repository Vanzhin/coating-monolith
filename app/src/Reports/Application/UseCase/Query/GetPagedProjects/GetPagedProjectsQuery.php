<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetPagedProjects;

use App\Reports\Domain\Repository\ProjectsFilter;
use App\Shared\Application\Query\Query;

final readonly class GetPagedProjectsQuery extends Query
{
    public function __construct(public ProjectsFilter $filter)
    {
    }
}
