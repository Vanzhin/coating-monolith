<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\Message\MessageBusInterface;
use App\Shared\Domain\Message\MessageInterface;
use App\Shared\Infrastructure\Http\Audit\HttpAuditMessage;
use Symfony\Component\Messenger\Envelope;

/**
 * Тест-дубль шины сообщений: собирает отправленные сообщения аудита HTTP, чтобы тест мог
 * проверить, какие записи сформировал аудируемый клиент, не поднимая транспорт.
 */
final class CollectingAuditBus implements MessageBusInterface
{
    /** @var list<HttpAuditMessage> */
    public array $records = [];

    public function executeMessages(MessageInterface ...$messages): void
    {
        foreach ($messages as $message) {
            if ($message instanceof HttpAuditMessage) {
                $this->records[] = $message;
            }
        }
    }

    public function execute(Envelope $envelope): void
    {
    }
}
