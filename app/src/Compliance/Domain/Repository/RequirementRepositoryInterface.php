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

    /**
     * Требования, покрывающие должность (jsonb-containment по position_ids). Для событийной пересборки
     * проекции учёта человека (Д3): union позиций всех требований его должности.
     *
     * @return Requirement[]
     */
    public function findByPositionId(string $positionId): array;

    /** Единый поиск/список требований через фильтр с пагинацией. */
    public function findByFilter(RequirementsFilter $filter): PaginationResult;
}
