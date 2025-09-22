<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\CreateChannel;

use App\Shared\Application\Command\Command;
use App\Users\Application\DTO\Channel\ChannelDTO;

readonly class CreateChannelCommand extends Command
{
    public function __construct(public ChannelDTO $dto)
    {
    }
}
