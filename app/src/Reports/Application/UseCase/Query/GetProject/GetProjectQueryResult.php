<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetProject;

use App\Reports\Application\DTO\Projects\ProjectDTO;

final readonly class GetProjectQueryResult
{
    public function __construct(public ?ProjectDTO $project)
    {
    }
}
