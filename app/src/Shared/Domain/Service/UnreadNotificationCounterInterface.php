<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service;

/**
 * Порт: число непрочитанных уведомлений владельца — для бейджа на иконке PWA. Позволяет
 * WebPushNotifier (Shared) класть счётчик в payload пуша, не завися от конкретики контекста
 * Notifications (реализация — там, инверсия зависимостей).
 */
interface UnreadNotificationCounterInterface
{
    public function countForOwner(string $ownerUlid): int;
}
