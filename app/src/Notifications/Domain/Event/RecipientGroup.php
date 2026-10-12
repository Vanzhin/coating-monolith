<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Группа событий одного получателя, одного типа, одной перспективы — единица, из которой раскладка делает
 * ОДИН текст (1 событие → персонально, несколько → сводка). Инварианты: не пусто, единый тип.
 */
final readonly class RecipientGroup
{
    /** @var list<NotifiableEvent> */
    private array $events;

    public function __construct(
        public string $userUlid,
        public DeliveryPerspective $perspective,
        NotifiableEvent ...$events,
    ) {
        if ([] === $events) {
            throw new AppException('Пустая стопка уведомлений.');
        }
        $type = $events[0]->notificationType();
        foreach ($events as $event) {
            if ($event->notificationType() !== $type) {
                throw new AppException('Стопка уведомлений смешивает типы.');
            }
        }
        $this->events = array_values($events);
    }

    public function type(): NotificationType
    {
        return $this->events[0]->notificationType();
    }

    /** @return list<NotifiableEvent> */
    public function events(): array
    {
        return $this->events;
    }
}
