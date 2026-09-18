<?php

declare(strict_types=1);

namespace App\Reports\Domain\Repository;

use App\Reports\Domain\Aggregate\Project\Project;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;

interface ProjectRepositoryInterface
{
    public function add(Project $project): void;

    public function remove(Project $project): void;

    public function findOneById(string $id): ?Project;

    public function findOneByTitle(string $title): ?Project;

    public function findByFilter(ProjectsFilter $filter): PaginationResult;

    /**
     * @return list<Project>
     */
    public function findByIds(StringCollection $ids): array;

    /**
     * @return list<Project>
     */
    public function suggest(string $query, int $limit = 10): array;
}
