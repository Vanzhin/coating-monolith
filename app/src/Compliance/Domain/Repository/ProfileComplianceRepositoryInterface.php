<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Repository;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
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

    /**
     * Активные обязанности с датой следующего срока не позже $threshold — прямое чтение проекции
     * `compliance_tracked_obligation` (минуя загрузку всех агрегатов) для прохода уведомлений due-soon.
     * `profileCompliance` (profileId) addSelect'ится в том же запросе — readonly-id + to-one на агрегат иначе
     * падает LAZY-прокси.
     *
     * @return list<TrackedObligation>
     */
    public function findDueSoonObligations(\DateTimeImmutable $threshold): array;
}
