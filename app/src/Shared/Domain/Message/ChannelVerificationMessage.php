<?php

declare(strict_types=1);

namespace App\Shared\Domain\Message;

readonly class ChannelVerificationMessage implements MessageInterface
{
    public function __construct(
        public string $channelId,
        public string $tokenString,
        public int $telegramChatId,
    ) {
    }
}
