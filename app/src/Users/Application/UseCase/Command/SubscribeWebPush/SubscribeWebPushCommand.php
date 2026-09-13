<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\SubscribeWebPush;

use App\Shared\Application\Command\Command;
use App\Users\Domain\Entity\ValueObject\PushSubscription;

/**
 * Подписка текущего пользователя на web push. Несёт разобранную подписку браузера (PushSubscription).
 * Владелец берётся из аутентификации в хендлере — подписаться можно только за себя.
 */
readonly class SubscribeWebPushCommand extends Command
{
    public function __construct(public PushSubscription $subscription)
    {
    }
}
