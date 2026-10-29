<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * Машинная обработка запроса завершилась (распознан или провален). Плоское доменное событие Inquiries —
 * без зависимости от Notifications (модули разделены). В уведомление его превращает переходник
 * InquiryProcessedNotifier (Infrastructure). НЕ поднимается на ручной правке (editParameters) — только
 * на recognize()/fail() конвейера.
 */
final readonly class InquiryProcessed implements EventInterface
{
    public function __construct(
        public string $inquiryId,
        public string $ownerId,
        public InquiryProcessingOutcome $outcome,
        public ?string $failureReason = null,
    ) {
    }
}
