<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Type;

/** Вид типа уведомления: System — принудительный (нельзя отписаться), Configurable — opt-in. */
enum NotificationKind: string
{
    case System = 'system';
    case Configurable = 'configurable';

    public function isSystem(): bool
    {
        return self::System === $this;
    }
}
