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
 * Подписка текущего устройства на web push. Канал на устройство: тот же endpoint уже есть —
 * переиспользуем (дубль не плодим), иначе заводим новый. Подписки других устройств пользователя
 * НЕ трогаем — пуш должен приходить на все устройства сразу. Протухшие/ротированные endpoint'ы
 * (Apple выдаёт новый при эвикции) чистятся по 410 при отправке (WebPushNotifier), не тут.
 * Канал WEB_PUSH рождается verified — OTP не нужен.
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

        $existing = $this->findChannelByEndpoint($userId, $subscription->endpoint);
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
