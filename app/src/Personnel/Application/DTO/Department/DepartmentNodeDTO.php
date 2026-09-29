<?php

declare(strict_types=1);

namespace App\Personnel\Application\DTO\Department;

/**
 * Узел дерева отделов компании (id/title/head + вложенные дети). Строится из плоского
 * списка Department в GetCompanyDepartmentTreeQueryHandler — сам DTO голый, без поведения.
 */
class DepartmentNodeDTO
{
    public string $id;
    public string $title;
    public ?string $headUserUlid;

    /** @var list<DepartmentNodeDTO> */
    public array $children = [];
}
