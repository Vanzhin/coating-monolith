<?php

declare(strict_types=1);

namespace App\Personnel\Application\DTO\Department;

class DepartmentDTO
{
    public string $id;
    public string $title;
    public string $companyId;
    public ?string $parentId;
    public ?string $headUserUlid;
}
