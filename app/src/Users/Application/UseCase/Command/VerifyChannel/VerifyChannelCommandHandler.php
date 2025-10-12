<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\VerifyChannel;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\AssertService;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use App\Shared\Domain\Service\TokenServiceInterface;

readonly class VerifyChannelCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ChannelRepositoryInterface $channelRepository,
        private TokenServiceInterface $tokenService,
    ) {
    }

    public function __invoke(VerifyChannelCommand $command): void
    {
        $channel = $this->channelRepository->findById($command->channelId);
        AssertService::notNull($channel, 'Канал не найден.');
        $channel->verify($this->tokenService, $command->tokenString);
        $this->channelRepository->add($channel);;
    }
}
