<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\SuggestDepartments;

use App\Personnel\Application\DTO\Department\DepartmentDTO;
use App\Shared\Domain\Repository\Pager;

final readonly class SuggestDepartmentsQueryResult
{
    /**
     * @param list<DepartmentDTO> $departments
     */
    public function __construct(public array $departments, public Pager $pager)
    {
    }
}
