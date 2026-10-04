<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetCompanyDepartmentTree;

use App\Personnel\Application\DTO\Department\DepartmentNodeDTO;

final readonly class GetCompanyDepartmentTreeQueryResult
{
    /**
     * @param list<DepartmentNodeDTO> $roots
     */
    public function __construct(public array $roots)
    {
    }
}
