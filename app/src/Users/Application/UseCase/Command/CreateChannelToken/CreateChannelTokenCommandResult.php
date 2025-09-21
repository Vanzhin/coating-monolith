<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\CreateChannelToken;

use App\Users\Application\DTO\TokenDTO;

class CreateChannelTokenCommandResult
{
    public function __construct(public TokenDTO $token)
    {
    }
}
