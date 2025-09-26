<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\EventHandler;

use App\Shared\Application\Event\EventHandlerInterface;
use App\Users\Domain\Event\ChannelVerifiedEvent;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use App\Users\Domain\Repository\UserRepositoryInterface;

readonly class ChannelVerifiedEventHandler implements EventHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private ChannelRepositoryInterface $channelRepository,
    ) {
    }

    public function __invoke(ChannelVerifiedEvent $event): void
    {
        $channel = $this->channelRepository->findById($event->channelId);

        if ($channel) {
            $user = $channel->getOwner();
            $user->makeActive();
            $this->userRepository->add($user);
        }
    }
}