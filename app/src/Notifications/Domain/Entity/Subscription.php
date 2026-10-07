<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Entity;

use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationType;
use Symfony\Component\Uid\Uuid;

/** Подписка пользователя на тип уведомления по конкретному каналу (включена/выключена). */
class Subscription
{
    public function __construct(
        private readonly Uuid $id,
        private readonly string $userUlid,
        private readonly NotificationType $type,
        private readonly NotificationChannel $channel,
        private bool $enabled,
    ) {
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getUserUlid(): string
    {
        return $this->userUlid;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
}
