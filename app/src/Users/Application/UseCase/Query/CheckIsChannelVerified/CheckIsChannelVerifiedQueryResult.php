<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\CheckIsChannelVerified;

class CheckIsChannelVerifiedQueryResult
{
    public function __construct(public bool $isChannelVerified)
    {
    }
}