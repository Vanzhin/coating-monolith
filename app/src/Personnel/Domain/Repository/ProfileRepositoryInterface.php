<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Repository;

use App\Personnel\Domain\Aggregate\Profile\Profile;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;

interface ProfileRepositoryInterface
{
    public function add(Profile $profile): void;

    public function remove(Profile $profile): void;

    public function findOneById(string $id): ?Profile;

    public function findOneByUserUlid(string $userUlid): ?Profile;

    public function findByFilter(ProfilesFilter $filter): PaginationResult;

    /** Профилей, где должность = $positionId — используется guard'ом удаления должности. */
    public function countByPositionId(string $positionId): int;

    /** Профилей, где отдел = $departmentId — используется guard'ом удаления отдела. */
    public function countByDepartmentId(string $departmentId): int;

    /**
     * @return list<Profile>
     */
    public function findByIds(StringCollection $ids): array;
}
