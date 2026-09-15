<?php

declare(strict_types=1);

namespace App\Notifications\Application\UseCase\Command\MarkNotificationsRead;

use App\Shared\Application\Command\Command;

/**
 * Пометить все уведомления пользователя прочитанными (заход в раздел = «увидел»). Владелец — из
 * аутентификации в контроллере.
 */
readonly class MarkNotificationsReadCommand extends Command
{
    public function __construct(public string $ownerUlid)
    {
    }
}
