<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\SubscribeWebPush;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Shared\Domain\Service\UuidService;
use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Entity\ValueObject\PushSubscription;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use App\Users\Domain\Service\UserFetcherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Подписка текущего юзера на web push. Одна подписка на юзера: вкл/выкл — единое состояние
 * (unsubscribe сносит все каналы). Поэтому при подписке убираем прежние WEB_PUSH-каналы, оставляя
 * ровно текущую — иначе дубли копятся (Apple выдаёт новый endpoint при каждой переподписке/эвикции,
 * дедуп по endpoint их не схлопывает). Канал WEB_PUSH рождается verified — OTP не нужен.
 */
readonly class SubscribeWebPushCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private AuthUserFetcherInterface $authUserFetcher,
        private UserFetcherInterface $userFetcher,
        private ChannelRepositoryInterface $channelRepository,
    ) {
    }

    public function __invoke(SubscribeWebPushCommand $command): SubscribeWebPushCommandResult
    {
        $userId = $this->authUserFetcher->getAuthUserId();
        $user = $this->userFetcher->getUserById($userId);
        $subscription = $command->subscription;

        // Одна подписка на юзера: сносим все прежние WEB_PUSH-каналы, кроме той же самой (по endpoint).
        // Так дубли не копятся ни при переподписке, ни при эвикции подписки iOS.
        $existing = $this->findChannelByEndpoint($userId, $subscription->endpoint);
        foreach ($this->channelRepository->findByOwnerAndType($userId, ChannelType::WEB_PUSH->value) as $channel) {
            if (null !== $existing && $channel->getId() === $existing->getId()) {
                continue;
            }
            $this->channelRepository->remove($channel);
        }
        if (null !== $existing) {
            return new SubscribeWebPushCommandResult($existing->getId());
        }

        $channel = new Channel(
            Uuid::fromString(UuidService::generate()),
            ChannelType::WEB_PUSH,
            $subscription->toJson(),
            $user,
        );
        $this->channelRepository->add($channel);

        return new SubscribeWebPushCommandResult($channel->getId());
    }

    private function findChannelByEndpoint(string $userId, string $endpoint): ?Channel
    {
        foreach ($this->channelRepository->findByOwnerAndType($userId, ChannelType::WEB_PUSH->value) as $channel) {
            if (PushSubscription::fromJson($channel->getValue())->endpoint === $endpoint) {
                return $channel;
            }
        }

        return null;
    }
}
