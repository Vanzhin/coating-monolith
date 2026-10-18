<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Repository;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;

interface ProfileComplianceRepositoryInterface
{
    public function add(ProfileCompliance $profileCompliance): void;

    public function findByProfile(string $profileId): ?ProfileCompliance;

    /**
     * profileId всех заведённых учётов — для служебной пересборки проекции И для read-путей дашборда,
     * которым нужен полный universe профилей с учётом без обхода агрегата (дашборд передаёт id-шки в
     * {@see ObligationProjectionRepositoryInterface::findForProfiles()}
     * за самими обязанностями — этот метод отдаёт только identity, не грузит обязанности).
     *
     * @return list<string>
     */
    public function findAllProfileIds(): array;
}
