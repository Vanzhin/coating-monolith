<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\VerifyChannel;

use App\Shared\Application\Command\Command;

readonly class VerifyChannelCommand extends Command
{
    public function __construct(public string $channelId, public string $tokenString)
    {
    }
}
