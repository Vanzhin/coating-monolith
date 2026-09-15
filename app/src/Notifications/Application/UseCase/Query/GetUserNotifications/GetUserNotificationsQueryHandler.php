<?php

declare(strict_types=1);

namespace App\Notifications\Application\UseCase\Query\GetUserNotifications;

use App\Notifications\Application\DTO\Notification\NotificationDTOTransformer;
use App\Notifications\Domain\Repository\NotificationFilter;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

readonly class GetUserNotificationsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private NotificationRepositoryInterface $notificationRepository,
        private NotificationDTOTransformer $dtoTransformer,
    ) {
    }

    public function __invoke(GetUserNotificationsQuery $query): GetUserNotificationsQueryResult
    {
        $pager = Pager::fromPage($query->page);
        $result = $this->notificationRepository->findByFilter(new NotificationFilter($query->ownerUlid, null, $pager));

        return new GetUserNotificationsQueryResult(
            $this->dtoTransformer->fromEntityList($result->items),
            new Pager($pager->page, $pager->perPage, $result->total),
        );
    }
}
