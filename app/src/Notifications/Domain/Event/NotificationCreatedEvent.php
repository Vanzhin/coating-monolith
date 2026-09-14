<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * Доменное событие: уведомление создано → нужно доставить. Несёт всё для рассылки без доп. загрузки
 * (владельца и текст) + id для дедупа событий на флаше. Обрабатывает NotificationCreatedEventHandler.
 */
class NotificationCreatedEvent implements EventInterface
{
    public function __construct(
        public string $notificationId,
        public string $ownerUlid,
        public string $message,
    ) {
    }
}
