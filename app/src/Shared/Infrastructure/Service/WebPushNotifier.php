<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Service;

use App\Shared\Domain\Service\NotifierInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Entity\ValueObject\PushSubscription;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;

/**
 * Канал Web Push. `Channel->value` хранит JSON подписки браузера ({endpoint, keys:{p256dh,auth}}).
 * Шлём через minishlink/web-push с VAPID. Мёртвую подписку (404/410) удаляем — не слать в пустоту.
 */
readonly class WebPushNotifier implements NotifierInterface
{
    private const NOTIFICATION_TITLE = 'Уведомление';

    public function __construct(
        private string $vapidPublicKey,
        private string $vapidPrivateKey,
        private string $vapidSubject,
        private ChannelRepositoryInterface $channelRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function sendVerificationCode(Channel $channel, string $code, int $timeToUse): void
    {
        // Web push не верифицируется OTP — согласие даёт браузер. Метод контракта, но no-op.
    }

    public function notify(Channel $channel, string $message): void
    {
        if (!$this->isSupportedChannel($channel)) {
            throw new AppException('Канал не поддерживается');
        }

        $subscription = $this->buildSubscription($channel);
        if (null === $subscription) {
            $this->logger->warning('Web push: битый JSON подписки', ['channel' => $channel->getId()]);

            return;
        }

        $webPush = new WebPush(['VAPID' => [
            'subject' => $this->vapidSubject,
            'publicKey' => $this->vapidPublicKey,
            'privateKey' => $this->vapidPrivateKey,
        ]]);

        $payload = json_encode(['title' => self::NOTIFICATION_TITLE, 'body' => $message], JSON_UNESCAPED_UNICODE);
        $report = $webPush->sendOneNotification($subscription, false === $payload ? null : $payload);

        if ($report->isSuccess()) {
            return;
        }
        if ($report->isSubscriptionExpired()) {
            // Подписка мертва — удаляем канал.
            $this->channelRepository->remove($channel);

            return;
        }
        $this->logger->warning('Web push не доставлен', [
            'endpoint' => $report->getEndpoint(),
            'reason' => $report->getReason(),
        ]);
    }

    public function isSupportedChannel(Channel $channel): bool
    {
        return ChannelType::WEB_PUSH === $channel->getType();
    }

    private function buildSubscription(Channel $channel): ?Subscription
    {
        try {
            $subscription = PushSubscription::fromJson($channel->getValue());
        } catch (AppException) {
            return null;
        }

        return Subscription::create($subscription->jsonSerialize());
    }
}
