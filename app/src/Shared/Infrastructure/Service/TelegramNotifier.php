<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Service;

use App\Notifications\Application\Service\Telegram\TelegramBotService;
use App\Shared\Domain\Service\NotifierInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Entity\ChannelType;

readonly class TelegramNotifier implements NotifierInterface
{
    public function __construct(private TelegramBotService $service)
    {
    }

    public function sendVerificationCode(Channel $channel, string $code, int $timeToUse): void
    {
        if (!$this->isSupportedChannel($channel)) {
            throw new AppException('Канал не поддерживается');
        }

        $message = sprintf(
            "🔐 Код верификации: %s\n\n⏰ Код действителен %d минут",
            $code,
            $timeToUse / 60
        );

        $this->service->sendMessage((int)$channel->getValue(), $message);
    }

    public function isSupportedChannel(Channel $channel): bool
    {
        return $channel->getType() === ChannelType::TELEGRAM;
    }
}

