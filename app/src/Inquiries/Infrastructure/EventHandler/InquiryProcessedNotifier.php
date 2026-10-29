<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\EventHandler;

use App\Inquiries\Domain\Event\InquiryProcessed;
use App\Inquiries\Domain\Event\InquiryProcessingOutcome;
use App\Notifications\Domain\Event\InquiryProcessedNotification;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Application\Event\EventHandlerInterface;

/**
 * Переходник «обработка завершилась → уведомление владельцу». Единственная точка связки Inquiries с
 * Notifications (Infrastructure — санкционированное место кросс-модульной склейки): плоское доменное
 * InquiryProcessed превращает в InquiryProcessedNotification, дальше единый NotificationDispatcher
 * доставит по подписке. Исход конвертим в bool — Notifications не знает enum Inquiries. Async.
 */
final readonly class InquiryProcessedNotifier implements EventHandlerInterface
{
    public function __construct(private EventBusInterface $eventBus)
    {
    }

    public function __invoke(InquiryProcessed $event): void
    {
        $this->eventBus->execute(new InquiryProcessedNotification(
            $event->ownerId,
            $event->inquiryId,
            InquiryProcessingOutcome::Failed === $event->outcome,
            $event->failureReason,
        ));
    }
}
