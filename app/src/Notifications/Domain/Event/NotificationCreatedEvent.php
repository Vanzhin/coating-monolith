<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * Доменное событие: уведомление создано → нужно доставить. Несёт только id — обработчик читает
 * уведомление из БД по нему (как ChannelVerifiedEvent). Событие уходит в async на postFlush, ДО
 * COMMIT транзакции команды: если строки ещё не видно (не закоммичена/откатилась), обработчик
 * бросит исключение и Messenger повторит доставку после коммита. Обрабатывает NotificationCreatedEventHandler.
 */
class NotificationCreatedEvent implements EventInterface
{
    public function __construct(public string $notificationId)
    {
    }
}
