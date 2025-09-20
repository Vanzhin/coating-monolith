<?php

declare(strict_types=1);

namespace App\Users\Domain\Entity;

use App\Shared\Domain\Trait\EnumToArray;

enum ChannelType: string
{
    use EnumToArray;

    // ТГ
    case TELEGRAM = 'telegram';

    // Email
    case EMAIL = 'email';
}