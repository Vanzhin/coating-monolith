<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\CheckIsChannelVerified;

use App\Shared\Application\Query\Query;

readonly class CheckIsChannelVerifiedQuery extends Query
{
    public function __construct(public string $channelValue, public string $type)
    {
    }
}