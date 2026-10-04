<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetDepartmentsByIds;

use App\Personnel\Application\DTO\Department\DepartmentDTO;

final readonly class GetDepartmentsByIdsQueryResult
{
    /**
     * @param list<DepartmentDTO> $departments
     */
    public function __construct(public array $departments)
    {
    }
}
