<?php

declare(strict_types=1);

namespace App\Personnel\Application\DTO\Department;

use App\Personnel\Domain\Aggregate\Department\Department;

class DepartmentDTOTransformer
{
    public function fromEntity(Department $department): DepartmentDTO
    {
        $dto = new DepartmentDTO();
        $dto->id = $department->getId();
        $dto->title = $department->getTitle();
        $dto->companyId = $department->getCompanyId();
        $dto->parentId = $department->getParentId();
        $dto->headUserUlid = $department->getHeadUserUlid();

        return $dto;
    }

    /**
     * @param iterable<Department> $departments
     *
     * @return list<DepartmentDTO>
     */
    public function fromEntityList(iterable $departments): array
    {
        $dtos = [];
        foreach ($departments as $department) {
            $dtos[] = $this->fromEntity($department);
        }

        return $dtos;
    }
}
