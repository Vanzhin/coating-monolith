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

    // Web Push (PWA, браузерная подписка)
    case WEB_PUSH = 'web_push';

    /**
     * Нужна ли каналу OTP-верификация пользователем. Email/Telegram — да (шлём код).
     * Web Push — нет: согласие санкционирует браузер, канал самоподтверждается при создании.
     */
    public function requiresVerification(): bool
    {
        return match ($this) {
            self::EMAIL, self::TELEGRAM => true,
            self::WEB_PUSH => false,
        };
    }
}
