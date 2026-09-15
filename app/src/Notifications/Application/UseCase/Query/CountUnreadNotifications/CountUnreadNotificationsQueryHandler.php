<?php

declare(strict_types=1);

namespace App\Notifications\Application\UseCase\Query\CountUnreadNotifications;

use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

readonly class CountUnreadNotificationsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private NotificationRepositoryInterface $notificationRepository,
    ) {
    }

    public function __invoke(CountUnreadNotificationsQuery $query): CountUnreadNotificationsQueryResult
    {
        return new CountUnreadNotificationsQueryResult($this->notificationRepository->countUnread($query->ownerUlid));
    }
}
