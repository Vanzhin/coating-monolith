<?php

declare(strict_types=1);

namespace App\Notifications\Application\UseCase\Query\CountUnreadNotifications;

class CountUnreadNotificationsQueryResult
{
    public function __construct(public int $count)
    {
    }
}
