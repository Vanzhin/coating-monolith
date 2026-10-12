<?php

declare(strict_types=1);

namespace App\Notifications\Application\EventHandler;

use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Event\NotificationDeliveryRequested;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Notifications\Domain\Service\NotificationAudienceProviderInterface;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Shared\Application\Event\EventHandlerInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Service\ChannelNotifierService;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Одна отправка — одно сообщение очереди, поэтому сбой/ретрай одного канала не трогает остальные.
 * inbox: запись Notification (адресат мог прийти из осиротевшей ссылки — проверяем existsUser) + пинг push
 * на web-push-устройства получателя (best-effort: сбой пинга не откатывает и не повторяет inbox).
 * email/прочие Users-каналы: через ChannelNotifierService.
 */
final readonly class NotificationDeliveryHandler implements EventHandlerInterface
{
    public function __construct(
        private NotificationRepositoryInterface $notifications,
        private ChannelRepositoryInterface $channels,
        private ChannelNotifierService $channelNotifier,
        private NotificationAudienceProviderInterface $audience,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NotificationDeliveryRequested $delivery): void
    {
        if (NotificationChannel::Inbox === $delivery->channel) {
            if (!$this->audience->existsUser($delivery->userUlid)) {
                return;
            }
            $this->notifications->add(new Notification(UuidService::generateUuid(), $delivery->userUlid, $delivery->message, new \DateTimeImmutable()));
            $this->pingPush($delivery->userUlid, $delivery->message);

            return;
        }

        $usersType = $delivery->channel->toUsersChannelType();
        if (null === $usersType) {
            return;
        }
        foreach ($this->channels->findByOwnerAndType($delivery->userUlid, $usersType->value) as $channel) {
            $this->channelNotifier->notify($channel, $delivery->message);
        }
    }

    /** Пинг «есть новое в приложении» на web-push-устройства. Best-effort — сбой не ломает inbox-доставку. */
    private function pingPush(string $userUlid, string $message): void
    {
        try {
            foreach ($this->channels->findByOwnerAndType($userUlid, ChannelType::WEB_PUSH->value) as $device) {
                $this->channelNotifier->notify($device, $message);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Пинг push при inbox-уведомлении не удался', ['user' => $userUlid, 'error' => $e->getMessage()]);
        }
    }
}
