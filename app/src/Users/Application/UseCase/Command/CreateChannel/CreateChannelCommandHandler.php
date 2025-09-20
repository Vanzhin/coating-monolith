<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\CreateChannel;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Shared\Domain\Service\AssertService;
use App\Users\Application\Service\AccessControl\UserAccessControl;
use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use App\Users\Domain\Service\UserFetcherInterface;
use Ramsey\Uuid\Uuid;

readonly class CreateChannelCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private UserAccessControl $accessControl,
        private UserFetcherInterface $userFetcher,
        private AuthUserFetcherInterface $authUserFetcher,
        private ChannelRepositoryInterface $channelRepository,
    ) {
    }

    public function __invoke(CreateChannelCommand $command): CreateChannelCommandResult
    {
        AssertService::true(
            $this->accessControl->canView($command->dto->owner_id, $this->authUserFetcher->getAuthUserId())
        );

        $user = $this->userFetcher->getUserById($command->dto->id);

        AssertService::notNull($user);

        $channel = new Channel(
            id: Uuid::fromString($command->dto->id),
            type: ChannelType::from($command->dto->type),
            value: $command->dto->value,
            owner: $user
        );

        $this->channelRepository->add($channel);

        return new CreateChannelCommandResult(
            $channel->getId()->toString(),
        );
    }
}
