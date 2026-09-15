<?php

declare(strict_types=1);

namespace App\Notifications\Application\DTO\Notification;

class NotificationDTO
{
    public function __construct(
        public string $id,
        public string $message,
        public \DateTimeImmutable $createdAt,
        public bool $isRead,
    ) {
    }
}
