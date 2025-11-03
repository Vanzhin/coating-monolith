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

        // Форматируем код в формате, который система распознает как OTP для автоматического заполнения
        // Формат с ключевым словом "Code" и цифрами позволяет системе автоматически заполнить поле ввода
        // На мобильных устройствах iOS и Android система может автоматически извлекать код из сообщений
        // Обернули код в тег <code> для возможности копирования при нажатии
        $message = sprintf(
            "🔐 Код верификации:\n\nCode: <code>%s</code>\n\n⏰ Код действителен %d минут\n\n",
            htmlspecialchars($code, ENT_QUOTES, 'UTF-8'),
            $timeToUse
        );

        $this->service->sendMessage((int)$channel->getValue(), $message);
    }

    public function notify(Channel $channel, string $message): void
    {
        if (!$this->isSupportedChannel($channel)) {
            throw new AppException('Канал не поддерживается');
        }

        // Форматируем сообщение для красивого отображения в Telegram
        $formattedMessage = $this->formatMessage($message);
        
        $this->service->sendMessage((int)$channel->getValue(), $formattedMessage);
    }

    /**
     * Форматирует сообщение для красивого отображения в Telegram
     */
    private function formatMessage(string $message): string
    {
        // Если сообщение уже содержит HTML-теги, используем его как есть
        if (strip_tags($message) !== $message) {
            return $message;
        }

        // Очищаем сообщение от возможных HTML-тегов и экранируем для безопасности
        $cleanMessage = htmlspecialchars(strip_tags($message), ENT_QUOTES, 'UTF-8');
        
        // Заменяем переносы строк на <br> для HTML-форматирования
        $cleanMessage = str_replace(["\r\n", "\r", "\n"], '<br>', $cleanMessage);
        
        // Форматируем сообщение с красивым оформлением
        $formatted = '<b>📬 Уведомление</b>' . "\n\n";
        $formatted .= $cleanMessage;

        return $formatted;
    }

    public function isSupportedChannel(Channel $channel): bool
    {
        return $channel->getType() === ChannelType::TELEGRAM;
    }
}

