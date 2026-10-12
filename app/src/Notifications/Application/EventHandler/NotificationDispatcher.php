<?php

declare(strict_types=1);

namespace App\Notifications\Application\EventHandler;

use App\Notifications\Application\Service\DeliveryPlanner;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Application\Event\EventHandlerInterface;

/**
 * Ловит любое уведомляющее событие (Messenger роутит по реализуемым интерфейсам). Сам не доставляет:
 * раскладывает событие в отправки «получатель × канал» ({@see DeliveryPlanner}) и публикует их. Доставка —
 * отдельными сообщениями ({@see NotificationDeliveryHandler}), по одной на канал.
 */
final readonly class NotificationDispatcher implements EventHandlerInterface
{
    public function __construct(
        private DeliveryPlanner $planner,
        private EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(NotifiableEvent $event): void
    {
        $this->eventBus->execute(...$this->planner->plan($event));
    }
}
