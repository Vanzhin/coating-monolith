<?php

declare(strict_types=1);

namespace App\Notifications\Application\DTO\Notification;

use App\Notifications\Domain\Entity\Notification;

readonly class NotificationDTOTransformer
{
    public function fromEntity(Notification $notification): NotificationDTO
    {
        return new NotificationDTO(
            $notification->getId(),
            $notification->getMessage(),
            $notification->getCreatedAt(),
            $notification->isRead(),
        );
    }

    /**
     * @param Notification[] $notifications
     *
     * @return NotificationDTO[]
     */
    public function fromEntityList(array $notifications): array
    {
        return array_map(fn (Notification $n): NotificationDTO => $this->fromEntity($n), $notifications);
    }
}
