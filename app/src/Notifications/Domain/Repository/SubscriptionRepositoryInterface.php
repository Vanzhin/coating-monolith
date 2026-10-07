<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Repository;

use App\Notifications\Domain\Entity\Subscription;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationType;

interface SubscriptionRepositoryInterface
{
    public function save(Subscription $subscription): void;

    /** @return list<Subscription> */
    public function findForUser(string $userUlid): array;

    public function isEnabled(string $userUlid, NotificationType $type, NotificationChannel $channel): bool;
}
