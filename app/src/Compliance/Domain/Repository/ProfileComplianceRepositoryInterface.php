<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Repository;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

interface ProfileComplianceRepositoryInterface
{
    public function add(ProfileCompliance $profileCompliance): void;

    public function findByProfile(string $profileId): ?ProfileCompliance;

    /**
     * Учёты с обязанностями для дашборда (fetch-join obligations+documents). Сужение по profileId
     * ($restrictProfileIds=null — без сужения; пустой — ничего) и по денорм-отделу. Бакеты/сорт/пагинация —
     * в PHP-хендлере (масштаб — сотрудники).
     *
     * @return list<ProfileCompliance>
     */
    public function findForDashboard(?StringCollection $restrictProfileIds, StringCollection $departmentIds): array;

    /**
     * profileId всех заведённых учётов — для служебной пересборки проекции.
     *
     * @return list<string>
     */
    public function findAllProfileIds(): array;
}
