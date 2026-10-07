<?php

declare(strict_types=1);

namespace App\Notifications\Application\EventHandler;

use App\Notifications\Application\Service\ChannelGate;
use App\Notifications\Application\Service\Render\MessageRendererRegistry;
use App\Notifications\Application\Service\Resolver\ResolverRegistry;
use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Notifications\Domain\Service\NotificationAudienceProviderInterface;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Shared\Application\Event\EventHandlerInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Service\ChannelNotifierService;
use App\Users\Domain\Repository\ChannelRepositoryInterface;

/**
 * Единая точка доставки уведомляющих событий. Ловит любое событие, реализующее NotifiableEvent
 * (Messenger роутит по реализуемым интерфейсам). Резолвит адресатов → по каждому выбирает каналы
 * (системные — все, настраиваемые — по подпискам) → рендерит текст → доставляет:
 * inbox = запись Notification; web_push/email = канал Users через ChannelNotifierService.
 * Async: роутится на воркер (messenger.yaml); в тестах зовётся напрямую.
 */
final readonly class NotificationDispatcher implements EventHandlerInterface
{
    public function __construct(
        private ResolverRegistry $resolvers,
        private ChannelGate $channelGate,
        private MessageRendererRegistry $renderer,
        private NotificationRepositoryInterface $notifications,
        private ChannelRepositoryInterface $channels,
        private ChannelNotifierService $channelNotifier,
        private NotificationAudienceProviderInterface $audience,
    ) {
    }

    public function __invoke(NotifiableEvent $event): void
    {
        $type = $event->notificationType();
        $message = $this->renderer->render($event);

        foreach ($this->resolvers->resolve($event) as $userUlid) {
            foreach ($this->channelGate->activeChannels($userUlid, $type) as $channel) {
                $this->deliver($channel, $userUlid, $message);
            }
        }
    }

    private function deliver(NotificationChannel $channel, string $userUlid, string $message): void
    {
        if (NotificationChannel::Inbox === $channel) {
            // inbox-запись ссылается на пользователя (FK notification.owner_id). Адресат мог прийти из
            // осиротевшей ссылки (profile.user_ulid / начальник отдела без FK на Users) — тогда пропускаем,
            // иначе вставка упала бы в воркере и утащила на ретрай всю рассылку.
            if (!$this->audience->existsUser($userUlid)) {
                return;
            }
            $this->notifications->add(new Notification(UuidService::generateUuid(), $userUlid, $message, new \DateTimeImmutable()));

            return;
        }
        $usersType = $channel->toUsersChannelType();
        if (null === $usersType) {
            return;
        }
        // Нет такого канала у адресата → этот канал тихо пропускаем, остальные идут.
        foreach ($this->channels->findByOwnerAndType($userUlid, $usersType->value) as $userChannel) {
            $this->channelNotifier->notify($userChannel, $message);
        }
    }
}
