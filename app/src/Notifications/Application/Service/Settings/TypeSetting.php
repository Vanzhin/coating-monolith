<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service\Settings;

use App\Notifications\Domain\Type\NotificationType;

/** Настраиваемый тип уведомления с его каналами (для экрана настроек). */
final readonly class TypeSetting
{
    /** @param list<ChannelSetting> $channels */
    public function __construct(
        public NotificationType $type,
        public array $channels,
    ) {
    }
}
