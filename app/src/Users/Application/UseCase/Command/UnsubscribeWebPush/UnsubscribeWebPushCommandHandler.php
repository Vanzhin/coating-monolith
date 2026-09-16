<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\UnsubscribeWebPush;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Repository\ChannelRepositoryInterface;

/**
 * Удаляет ВСЕ WEB_PUSH-каналы текущего пользователя — выключение пуша сразу на всех устройствах.
 * Идемпотентно: нет каналов — ничего не делает. Заодно это чистит накопившихся «сирот»
 * (протухшие подписки того же устройства), которые иначе ждут 410 при отправке.
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
        $userId = $this->authUserFetcher->getAuthUserId();
        foreach ($this->channelRepository->findByOwnerAndType($userId, ChannelType::WEB_PUSH->value) as $channel) {
            $this->channelRepository->remove($channel);
        }
    }
}
