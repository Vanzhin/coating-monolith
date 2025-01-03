<?php

namespace App\Shared\Domain\Aggregate;

use App\Shared\Domain\Service\UuidService;

class Id
{
    public static function makeUlid(): string
    {
        return UuidService::generate();
    }
}
