<?php

declare(strict_types=1);

namespace App\Notifications\Application\UseCase\Command\SendNotification;

use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\UuidService;
use Symfony\Component\Uid\Uuid;

/**
 * Создаёт и сохраняет уведомление пользователя. Само сохранение (persist+flush) публикует
 * NotificationCreatedEvent, который асинхронно доставляется в каналы владельца
 * (NotificationCreatedEventHandler в воркере) — здесь только запись, без рассылки.
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
            Uuid::fromString(UuidService::generate()),
            $command->ownerUlid,
            $command->message,
            new \DateTimeImmutable(),
        );

        $this->notificationRepository->add($notification);
    }
}
