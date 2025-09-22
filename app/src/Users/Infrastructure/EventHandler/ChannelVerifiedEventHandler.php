<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\EventHandler;

use App\Shared\Application\Event\EventHandlerInterface;
use App\Users\Domain\Event\ChannelVerifiedEvent;
use App\Users\Domain\Repository\UserRepositoryInterface;

readonly class ChannelVerifiedEventHandler implements EventHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {
    }

    public function __invoke(ChannelVerifiedEvent $event): void
    {
        $user = $this->userRepository->getByUlid($event->ownerId);

        if ($user) {
            $user->makeActive();
            $this->userRepository->add($user);
        }
    }
}