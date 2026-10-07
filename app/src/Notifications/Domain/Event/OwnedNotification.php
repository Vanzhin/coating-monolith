<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

/** Событие адресовано конкретному пользователю (резолвер Owner). */
interface OwnedNotification
{
    public function ownerUlid(): string;
}
