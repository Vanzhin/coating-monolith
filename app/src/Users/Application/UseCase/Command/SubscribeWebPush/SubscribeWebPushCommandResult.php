<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\SubscribeWebPush;

readonly class SubscribeWebPushCommandResult
{
    public function __construct(public string $channelId)
    {
    }
}
