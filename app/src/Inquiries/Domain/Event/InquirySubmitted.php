<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * Запрос создан и ждёт обработки. Раздаётся на flush (после коммита транзакции) — его слушает
 * ProcessInquiryHandler в воркере (async) и запускает конвейер подбора. Никакой гонки с коммитом:
 * публикует PublishDomainEventsOnFlushListener через EventBus с DispatchAfterCurrentBusStamp.
 */
final readonly class InquirySubmitted implements EventInterface
{
    public function __construct(public string $inquiryId)
    {
    }
}
