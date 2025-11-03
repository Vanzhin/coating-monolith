<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Service;

use App\Shared\Domain\Service\NotifierInterface;
use App\Users\Domain\Entity\ChannelType;

readonly class NotifierFactory
{
    public function __construct(
        private EmailNotifier $emailNotifier,
        private TelegramNotifier $telegramNotifier,
    ) {
    }

    public function create(ChannelType $channelType): NotifierInterface
    {
        return match ($channelType) {
            ChannelType::EMAIL => $this->emailNotifier,
            ChannelType::TELEGRAM => $this->telegramNotifier,
        };
    }
}

