<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Type;

use App\Users\Domain\Entity\ChannelType;

/** Канал доставки уведомления. Inbox — запись Notification (не внешний канал Users). */
enum NotificationChannel: string
{
    case Inbox = 'inbox';
    case WebPush = 'web_push';
    case Email = 'email';

    /** Канал Users для внешней доставки; null для inbox (это запись Notification, не Users\Channel). */
    public function toUsersChannelType(): ?ChannelType
    {
        return match ($this) {
            self::Inbox => null,
            self::WebPush => ChannelType::WEB_PUSH,
            self::Email => ChannelType::EMAIL,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Inbox => 'В приложении',
            self::WebPush => 'Push',
            self::Email => 'Почта',
        };
    }
}
