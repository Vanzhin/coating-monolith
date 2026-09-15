<?php

declare(strict_types=1);

namespace App\Notifications\Application\UseCase\Command\MarkNotificationsRead;

use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class MarkNotificationsReadCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private NotificationRepositoryInterface $notificationRepository,
    ) {
    }

    public function __invoke(MarkNotificationsReadCommand $command): void
    {
        $this->notificationRepository->markAllReadForOwner($command->ownerUlid);
    }
}
