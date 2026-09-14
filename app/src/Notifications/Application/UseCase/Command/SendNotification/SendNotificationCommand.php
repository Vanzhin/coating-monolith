<?php

declare(strict_types=1);

namespace App\Notifications\Application\UseCase\Command\SendNotification;

use App\Shared\Application\Command\Command;

/**
 * Уведомить пользователя. Хендлер создаёт и сохраняет Notification; доставка (рассылка по каналам)
 * происходит асинхронно через доменное событие NotificationCreatedEvent (см. messenger.yaml).
 */
readonly class SendNotificationCommand extends Command
{
    public function __construct(
        public string $ownerUlid,
        public string $message,
    ) {
    }
}
