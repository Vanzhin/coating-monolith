<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Service;

use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Shared\Domain\Service\UnreadNotificationCounterInterface;

readonly class UnreadNotificationCounter implements UnreadNotificationCounterInterface
{
    public function __construct(
        private NotificationRepositoryInterface $notificationRepository,
    ) {
    }

    public function countForOwner(string $ownerUlid): int
    {
        return $this->notificationRepository->countUnread($ownerUlid);
    }
}
