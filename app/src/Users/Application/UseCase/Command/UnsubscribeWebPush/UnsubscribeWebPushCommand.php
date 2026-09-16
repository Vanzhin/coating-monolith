<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\UnsubscribeWebPush;

use App\Shared\Application\Command\Command;

/**
 * Отписать текущее устройство пользователя от web push — удаляем его WEB_PUSH-канал по endpoint.
 * Канал на устройство: выключение на одном устройстве не трогает подписки других. Владелец берётся
 * из аутентификации в хендлере, endpoint — от браузера (какую именно подписку гасим).
 */
readonly class UnsubscribeWebPushCommand extends Command
{
    public function __construct(public string $endpoint)
    {
    }
}
