<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\FindChannel;

use App\Users\Application\DTO\Channel\ChannelDTO;

class FindChannelQueryResult
{
    public function __construct(public ?ChannelDTO $channel)
    {
    }
}