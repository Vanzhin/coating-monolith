<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service\Settings;

use App\Notifications\Domain\Type\NotificationChannel;

/** Канал типа уведомления с текущим состоянием подписки (для экрана настроек). */
final readonly class ChannelSetting
{
    public function __construct(
        public NotificationChannel $channel,
        public bool $enabled,
    ) {
    }
}
