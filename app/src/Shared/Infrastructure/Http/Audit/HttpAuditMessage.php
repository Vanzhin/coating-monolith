<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Audit;

use App\Shared\Domain\Message\MessageInterface;

/**
 * Несёт снимок одной попытки до стока асинхронно (Messenger), чтобы запись в БД не
 * блокировала и не ломала реальный HTTP-вызов. Обрабатывается в воркере.
 */
final readonly class HttpAuditMessage implements MessageInterface
{
    public function __construct(public HttpAuditRecord $record)
    {
    }
}
