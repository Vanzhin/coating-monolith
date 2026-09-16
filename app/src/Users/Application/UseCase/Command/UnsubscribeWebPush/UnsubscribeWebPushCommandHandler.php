<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\UnsubscribeWebPush;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Entity\ValueObject\PushSubscription;
use App\Users\Domain\Repository\ChannelRepositoryInterface;

/**
 * Удаляет WEB_PUSH-канал текущего пользователя, совпадающий по endpoint — отписка ровно того
 * устройства, что нажало «выкл». Подписки других устройств не трогаем: пуш должен идти на все.
 * Идемпотентно: нет совпадения (пустой endpoint / уже удалён) — ничего не делает. Протухшие
 * подписки того же устройства самочистятся по 410 при следующей отправке (WebPushNotifier).
 */
readonly class UnsubscribeWebPushCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private AuthUserFetcherInterface $authUserFetcher,
        private ChannelRepositoryInterface $channelRepository,
    ) {
    }

    public function __invoke(UnsubscribeWebPushCommand $command): void
    {
        if ('' === $command->endpoint) {
            return;
        }

        $userId = $this->authUserFetcher->getAuthUserId();
        foreach ($this->channelRepository->findByOwnerAndType($userId, ChannelType::WEB_PUSH->value) as $channel) {
            if (PushSubscription::fromJson($channel->getValue())->endpoint === $command->endpoint) {
                $this->channelRepository->remove($channel);
            }
        }
    }
}
