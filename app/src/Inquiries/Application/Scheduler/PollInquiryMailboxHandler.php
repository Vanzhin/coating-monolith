<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Scheduler;

use App\Inquiries\Application\Service\InquiryMailboxPoller;
use App\Shared\Application\Command\CommandHandlerInterface;

/**
 * Обработчик периодического сигнала Scheduler: прогоняет опрос ящика. Реализует CommandHandlerInterface —
 * регистрируется на command.bus, куда Scheduler диспатчит сообщение. Не настроен ящик → поллер молча выходит.
 */
final readonly class PollInquiryMailboxHandler implements CommandHandlerInterface
{
    private const BATCH = 50;

    public function __construct(private InquiryMailboxPoller $poller)
    {
    }

    public function __invoke(PollInquiryMailbox $message): void
    {
        $this->poller->poll(self::BATCH);
    }
}
