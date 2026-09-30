<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Repository;

use App\Compliance\Domain\Aggregate\Requirement\Requirement;
use App\Shared\Domain\Repository\PaginationResult;

interface RequirementRepositoryInterface
{
    public function add(Requirement $requirement): void;

    public function remove(Requirement $requirement): void;

    public function findOneById(string $id): ?Requirement;

    /** Единый поиск/список требований через фильтр с пагинацией. */
    public function findByFilter(RequirementsFilter $filter): PaginationResult;
}
