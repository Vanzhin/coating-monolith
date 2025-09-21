<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\EventHandler;

use App\Shared\Application\Event\EventHandlerInterface;
use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Event\UserCreatedEvent;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use App\Users\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Uid\Uuid;

readonly class UserCreatedEventHandler implements EventHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private ChannelRepositoryInterface $channelRepository,
    ) {
    }

    public function __invoke(UserCreatedEvent $event): void
    {
        $user = $this->userRepository->getByUlid($event->userId);

        if ($user) {
            $channel = new Channel(
                id: Uuid::v4(),
                type: ChannelType::EMAIL,
                value: $user->getEmail(),
                owner: $user
            );
            $this->channelRepository->add($channel);
        }
    }
}