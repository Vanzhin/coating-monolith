<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Repository;

use App\Compliance\Domain\Entity\DueNotificationState;

interface DueNotificationStateRepositoryInterface
{
    public function findOne(string $profileId, string $obligationKey): ?DueNotificationState;

    public function save(DueNotificationState $state): void;
}
