<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service;

use Symfony\Component\Uid\Uuid;

class UuidService
{
    public static function generate(): string
    {
        return Uuid::v4()->toRfc4122();
    }
}
