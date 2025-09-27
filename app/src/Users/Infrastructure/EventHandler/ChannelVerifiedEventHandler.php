<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\EventHandler;

use App\Shared\Application\Event\EventHandlerInterface;
use App\Users\Domain\Event\ChannelVerifiedEvent;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use App\Users\Domain\Repository\UserRepositoryInterface;
use App\Users\Domain\Service\Validation\EmailValidatorInterface;

readonly class ChannelVerifiedEventHandler implements EventHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private ChannelRepositoryInterface $channelRepository,
        private EmailValidatorInterface $emailValidator,
    ) {
    }

    public function __invoke(ChannelVerifiedEvent $event): void
    {
        $channel = $this->channelRepository->findById($event->channelId);

        if ($channel) {
            $user = $channel->getOwner();
            $this->emailValidator->isEmailValid($user->getEmail());
            $user->makeActiveInternally();
            $this->userRepository->add($user);
        }
    }
}