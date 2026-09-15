<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Repository;

use App\Notifications\Domain\Entity\Notification;
use App\Shared\Domain\Repository\PaginationResult;

interface NotificationRepositoryInterface
{
    public function add(Notification $notification): void;

    public function findById(string $id): ?Notification;

    public function findByFilter(NotificationFilter $filter): PaginationResult;

    /**
     * Число непрочитанных уведомлений владельца — источник для бейджа на иконке PWA.
     */
    public function countUnread(string $ownerUlid): int;

    /**
     * Помечает все непрочитанные уведомления владельца прочитанными (заход в раздел = «увидел»).
     */
    public function markAllReadForOwner(string $ownerUlid): void;
}
