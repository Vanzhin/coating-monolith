<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\SuggestDepartments;

use App\Personnel\Domain\Repository\DepartmentsFilter;
use App\Shared\Application\Query\Query;

/** Постраничный поиск отделов для typeahead — через единый DepartmentsFilter (title + companyId-скоуп). */
final readonly class SuggestDepartmentsQuery extends Query
{
    public function __construct(public DepartmentsFilter $filter)
    {
    }
}
