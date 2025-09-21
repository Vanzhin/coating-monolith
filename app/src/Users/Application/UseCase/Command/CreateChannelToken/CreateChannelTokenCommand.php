<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\CreateChannelToken;

use App\Shared\Application\Command\Command;

readonly class CreateChannelTokenCommand extends Command
{
    public function __construct(public string $channelId)
    {
    }
}
