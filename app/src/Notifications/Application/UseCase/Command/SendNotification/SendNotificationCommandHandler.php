<?php

declare(strict_types=1);

namespace App\Notifications\Application\UseCase\Command\SendNotification;

use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\UuidService;

/**
 * Создаёт и сохраняет inbox-уведомление пользователя. Только запись в раздел «Уведомления» —
 * рассылка по каналам (push/email) идёт отдельно, через NotificationDispatcher по подпискам.
 */
readonly class SendNotificationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private NotificationRepositoryInterface $notificationRepository,
    ) {
    }

    public function __invoke(SendNotificationCommand $command): void
    {
        $notification = new Notification(
            UuidService::generateUuid(),
            $command->ownerUlid,
            $command->message,
            new \DateTimeImmutable(),
        );

        $this->notificationRepository->add($notification);
    }
}
