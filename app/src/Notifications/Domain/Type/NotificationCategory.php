<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Type;

/** Тематическая группировка типов уведомлений для экрана настроек. */
enum NotificationCategory: string
{
    case Compliance = 'compliance';
    case Security = 'security';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Compliance => 'Учёт СИЗ',
            self::Security => 'Безопасность',
            self::System => 'Системные',
        };
    }
}
