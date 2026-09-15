<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\EventHandler;

use App\Notifications\Domain\Event\NotificationCreatedEvent;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Shared\Application\Event\EventHandlerInterface;
use App\Shared\Infrastructure\Service\ChannelNotifierService;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Repository\ChannelRepositoryInterface;

/**
 * Асинхронная рассылка уведомления. Событие роутится на async (messenger.yaml) → этот обработчик
 * выполняется в воркере (manager_supervisor), доставка в web push (I/O) не блокирует запрос.
 *
 * Событие несёт только id и публикуется на postFlush — ДО COMMIT транзакции команды. Читаем
 * уведомление из БД по id: если строки ещё/уже нет (не закоммичена или откатилась) — бросаем, и
 * Messenger повторит доставку позже (дефолт: 3 ретрая, backoff 1с/2с/4с). Так бейдж считается по
 * реально видимой строке (нет недосчёта), а откат не рождает «призрачный» пуш.
 *
 * Доставляем в WEB_PUSH-каналы владельца — это «приложение». Другие каналы (почта/телеграм) —
 * будущий слой настраиваемых подписок, здесь не трогаем.
 */
readonly class NotificationCreatedEventHandler implements EventHandlerInterface
{
    public function __construct(
        private NotificationRepositoryInterface $notificationRepository,
        private ChannelRepositoryInterface $channelRepository,
        private ChannelNotifierService $channelNotifierService,
    ) {
    }

    public function __invoke(NotificationCreatedEvent $event): void
    {
        $notification = $this->notificationRepository->findById($event->notificationId);
        if (null === $notification) {
            // Ещё не закоммичена (гонка с postFlush) или откатилась — пусть Messenger повторит.
            throw new \RuntimeException(sprintf('Уведомление %s не видно в БД — повтор доставки.', $event->notificationId));
        }

        foreach ($this->channelRepository->findByOwnerAndType($notification->getOwnerUlid(), ChannelType::WEB_PUSH->value) as $channel) {
            $this->channelNotifierService->notify($channel, $notification->getMessage());
        }
    }
}
