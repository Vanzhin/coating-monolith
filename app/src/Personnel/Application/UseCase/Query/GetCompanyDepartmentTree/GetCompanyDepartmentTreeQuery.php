<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetCompanyDepartmentTree;

use App\Shared\Application\Query\Query;

final readonly class GetCompanyDepartmentTreeQuery extends Query
{
    public function __construct(public string $companyId)
    {
    }
}
