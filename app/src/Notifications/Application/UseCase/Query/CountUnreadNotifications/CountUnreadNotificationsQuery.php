<?php

declare(strict_types=1);

namespace App\Notifications\Application\UseCase\Query\CountUnreadNotifications;

use App\Shared\Application\Query\Query;

readonly class CountUnreadNotificationsQuery extends Query
{
    public function __construct(public string $ownerUlid)
    {
    }
}
