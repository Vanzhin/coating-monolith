<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service\Telegram\Command;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;

class StartCommand extends UserCommand
{
    protected $name = 'start';
    protected $description = 'Start command';
    protected $usage = '/start';
    protected $version = '1.0';

    /**
     * @throws TelegramException
     */
    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $text = $this->buildDefaultTextMessage($message);

        return $this->replyWithMessage($text);
    }

    /**
     * @throws TelegramException
     */
    private function replyWithMessage(string $text): ServerResponse
    {
        return $this->replyToChat($text);
    }

    private function buildDefaultTextMessage(Message $message): string
    {
        $firstName = $message->getFrom()->getFirstName() ?? 'Пользователь';

        $text = "👋 Привет, {$firstName}!\n\n";
        $text .= "Я бот 1helper.\n";

        return $text;
    }
}

