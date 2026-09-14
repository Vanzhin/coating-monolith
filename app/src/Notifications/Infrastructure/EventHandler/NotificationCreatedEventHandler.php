<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\EventHandler;

use App\Notifications\Domain\Event\NotificationCreatedEvent;
use App\Shared\Application\Event\EventHandlerInterface;
use App\Shared\Infrastructure\Service\ChannelNotifierService;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Repository\ChannelRepositoryInterface;

/**
 * Асинхронная рассылка уведомления. Событие NotificationCreatedEvent роутится на async-транспорт
 * (см. messenger.yaml) → этот обработчик выполняется в воркере (manager_supervisor), а не в запросе:
 * доставка в web push — I/O (FCM), запрос на неё не блокируется.
 *
 * Доставляем в WEB_PUSH-каналы владельца — это «приложение». Другие каналы (почта/телеграм) —
 * будущий слой настраиваемых подписок, здесь не трогаем.
 */
readonly class NotificationCreatedEventHandler implements EventHandlerInterface
{
    public function __construct(
        private ChannelRepositoryInterface $channelRepository,
        private ChannelNotifierService $channelNotifierService,
    ) {
    }

    public function __invoke(NotificationCreatedEvent $event): void
    {
        foreach ($this->channelRepository->findByOwnerAndType($event->ownerUlid, ChannelType::WEB_PUSH->value) as $channel) {
            $this->channelNotifierService->notify($channel, $event->message);
        }
    }
}
