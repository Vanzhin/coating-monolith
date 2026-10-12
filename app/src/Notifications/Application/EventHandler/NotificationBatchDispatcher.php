<?php

declare(strict_types=1);

namespace App\Notifications\Application\EventHandler;

use App\Notifications\Application\Service\DeliveryPlanner;
use App\Notifications\Domain\Event\NotificationBatch;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Application\Event\EventHandlerInterface;

/**
 * Обработчик пачки от источника-прохода: раскладывает ВСЕ события пачки сразу — так один получатель (админ)
 * получает одно сводное сообщение, а не по одному на каждого сотрудника. Публикует отправки.
 */
final readonly class NotificationBatchDispatcher implements EventHandlerInterface
{
    public function __construct(
        private DeliveryPlanner $planner,
        private EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(NotificationBatch $batch): void
    {
        $this->eventBus->execute(...$this->planner->plan(...$batch->events()));
    }
}
