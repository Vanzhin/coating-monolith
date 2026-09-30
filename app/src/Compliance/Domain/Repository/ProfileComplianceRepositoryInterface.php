<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Repository;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;

interface ProfileComplianceRepositoryInterface
{
    public function add(ProfileCompliance $profileCompliance): void;

    public function findByProfile(string $profileId): ?ProfileCompliance;

    /**
     * profileId всех заведённых учётов — для служебной пересборки проекции.
     *
     * @return list<string>
     */
    public function findAllProfileIds(): array;
}
