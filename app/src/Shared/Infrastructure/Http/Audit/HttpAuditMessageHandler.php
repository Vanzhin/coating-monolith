<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Audit;

use App\Shared\Application\Message\MessageHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Пишет снимок попытки в сток (в воркере, транспорт async). Best-effort: сбой записи
 * логируем и глотаем — аудит не должен ронять обработку очереди; сам HTTP-вызов к этому
 * моменту давно завершён.
 */
final readonly class HttpAuditMessageHandler implements MessageHandlerInterface
{
    public function __construct(
        private HttpAuditSink $sink,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(HttpAuditMessage $message): void
    {
        try {
            $this->sink->record($message->record);
        } catch (\Throwable $e) {
            $this->logger->error('Не удалось записать аудит HTTP-запроса', [
                'exception' => $e,
                'correlation_id' => $message->record->correlationId,
            ]);
        }
    }
}
