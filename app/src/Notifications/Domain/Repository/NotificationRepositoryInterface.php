<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Repository;

use App\Notifications\Domain\Entity\Notification;

interface NotificationRepositoryInterface
{
    public function add(Notification $notification): void;

    /**
     * Число непрочитанных уведомлений владельца — источник для бейджа на иконке PWA.
     */
    public function countUnread(string $ownerUlid): int;

    /**
     * Помечает все непрочитанные уведомления владельца прочитанными (заход в раздел = «увидел»).
     */
    public function markAllReadForOwner(string $ownerUlid): void;
}
