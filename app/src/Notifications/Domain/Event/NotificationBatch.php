<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

use App\Shared\Domain\Event\EventInterface;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Пачка уведомляющих событий от источника-прохода (все события одного прогона). Публикуется одним сообщением;
 * раскладка схлопывает её по получателям. Сама НЕ реализует NotifiableEvent — не идёт в обычный диспетчер,
 * у неё свой обработчик.
 */
final readonly class NotificationBatch implements EventInterface
{
    /** @var list<NotifiableEvent> */
    private array $events;

    public function __construct(NotifiableEvent ...$events)
    {
        if ([] === $events) {
            throw new AppException('Пустая пачка уведомлений.');
        }
        $this->events = array_values($events);
    }

    /** @return list<NotifiableEvent> */
    public function events(): array
    {
        return $this->events;
    }
}
