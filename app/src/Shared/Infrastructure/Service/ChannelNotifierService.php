<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Service;

use App\Users\Domain\Entity\Channel;

readonly class ChannelNotifierService
{
    public function __construct(
        private NotifierFactory $notifierFactory,
    ) {
    }

    /**
     * Отправляет код верификации в канал
     */
    public function sendVerificationCode(Channel $channel, string $code, int $timeToUse): void
    {
        $notifier = $this->notifierFactory->create($channel->getType());
        $notifier->sendVerificationCode($channel, $code, $timeToUse);
    }

    /**
     * Отправляет обычное сообщение в канал
     */
    public function notify(Channel $channel, string $message): void
    {
        $notifier = $this->notifierFactory->create($channel->getType());
        $notifier->notify($channel, $message);
    }
}

