<?php

declare(strict_types=1);

namespace App\Notifications\Application\UseCase\Query\GetUserNotifications;

use App\Shared\Application\Query\Query;

readonly class GetUserNotificationsQuery extends Query
{
    public function __construct(
        public string $ownerUlid,
        public int $page = 1,
    ) {
    }
}
