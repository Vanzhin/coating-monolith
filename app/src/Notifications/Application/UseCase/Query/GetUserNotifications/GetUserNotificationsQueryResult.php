<?php

declare(strict_types=1);

namespace App\Notifications\Application\UseCase\Query\GetUserNotifications;

use App\Notifications\Application\DTO\Notification\NotificationDTO;
use App\Shared\Domain\Repository\Pager;

class GetUserNotificationsQueryResult
{
    /**
     * @param NotificationDTO[] $notifications
     */
    public function __construct(
        public array $notifications,
        public Pager $pager,
    ) {
    }
}
