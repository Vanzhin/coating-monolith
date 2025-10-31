<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\CreateChannelToken;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\AssertService;
use App\Users\Application\DTO\TokenDTO;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use App\Shared\Domain\Service\TokenServiceInterface;

readonly class CreateChannelTokenCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ChannelRepositoryInterface $channelRepository,
        private TokenServiceInterface $tokenService,
    ) {
    }

    public function __invoke(CreateChannelTokenCommand $command): CreateChannelTokenCommandResult
    {
        $channel = $this->channelRepository->findById($command->channelId);

        AssertService::notNull($channel, 'Канал не найден.');

        $token = $this->tokenService->makeToken($channel);

        return new CreateChannelTokenCommandResult(
            new TokenDTO(
                token: $token->getToken(),
                subjectId: $token->getSubjectId(),
                expiresAt: $token->getExpiresAt()->format(DATE_ATOM)
            ),
        );
    }
}
