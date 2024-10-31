<?php

namespace App\Shared\Infrastructure\Log;

use Monolog\Handler\TelegramBotHandler;
use Monolog\Level;

class TelegramLogHandler extends TelegramBotHandler
{
    public function __construct(
        string $apiKey,
        string $channel,
        $level = Level::Debug,
        bool $bubble = true,
        string $parseMode = 'Markdown',
        ?bool $disableWebPagePreview = null,
        ?bool $disableNotification = null,
        bool $splitLongMessages = true,
        bool $delayBetweenMessages = false
    ) {
        $this->setFormatter(new TelegramFormatter());
        parent::__construct(
            $apiKey,
            $channel,
            $level,
            $bubble,
            $parseMode,
            $disableWebPagePreview,
            $disableNotification,
            $splitLongMessages,
            $delayBetweenMessages
        );
    }
}
