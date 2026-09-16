<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\UnsubscribeWebPush;

use App\Shared\Application\Command\Command;

/**
 * Отписать текущего пользователя от web push — удаляем ВСЕ его WEB_PUSH-каналы (на всех устройствах).
 * Выключение пуша задумано как «выкл везде», чтобы не путаться, где подписка есть, а где нет.
 * Владелец берётся из аутентификации в хендлере.
 */
readonly class UnsubscribeWebPushCommand extends Command
{
}
