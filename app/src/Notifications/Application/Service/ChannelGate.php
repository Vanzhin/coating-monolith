<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service;

use App\Notifications\Domain\Repository\SubscriptionRepositoryInterface;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationType;

/** Какие каналы активны для адресата по типу: системный → все разрешённые; настраиваемый → по подпискам. */
final readonly class ChannelGate
{
    public function __construct(private SubscriptionRepositoryInterface $subscriptions)
    {
    }

    /** @return list<NotificationChannel> */
    public function activeChannels(string $userUlid, NotificationType $type): array
    {
        if ($type->kind()->isSystem()) {
            return $type->channels();
        }
        $active = [];
        foreach ($type->channels() as $channel) {
            if ($this->subscriptions->isEnabled($userUlid, $type, $channel)) {
                $active[] = $channel;
            }
        }

        return $active;
    }
}
