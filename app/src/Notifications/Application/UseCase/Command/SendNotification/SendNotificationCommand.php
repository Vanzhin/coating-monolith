<?php

declare(strict_types=1);

namespace App\Notifications\Application\UseCase\Command\SendNotification;

use App\Shared\Application\Command\Command;

/**
 * Создать inbox-уведомление пользователю (запись в раздел «Уведомления»). Рассылка по внешним каналам
 * (push/email) идёт отдельно — через NotificationDispatcher по подпискам, не из этой команды.
 */
readonly class SendNotificationCommand extends Command
{
    public function __construct(
        public string $ownerUlid,
        public string $message,
    ) {
    }
}
