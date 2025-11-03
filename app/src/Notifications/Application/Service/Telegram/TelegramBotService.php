<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service\Telegram;

use App\Shared\Application\Message\MessageBusInterface;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Psr\Log\LoggerInterface;

final readonly class TelegramBotService
{
    public function __construct(
        private string $webhookUrl,
        private string $commandPath,
        private string $secret,
        private Telegram $telegram,
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
        private ChannelRepositoryInterface $channelRepository,
    ) {
        $this->addCommandsPath();
        $this->configureCommands();
    }

    /**
     * Установка вебхука.
     *
     * @throws TelegramException
     */
    public function setWebhook(): ServerResponse
    {
        return $this->telegram->setWebhook($this->webhookUrl, [
            'secret_token' => $this->secret,
        ]);
    }

    /**
     * Отправка простого сообщения.
     */
    public function sendMessage(int $chatId, string $text, array $options = []): ServerResponse
    {
        try {
            $params = array_merge([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ], $options);

            return Request::sendMessage($params);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send Telegram message', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Обработка входящих запросов.
     */
    public function handle(string $secret): void
    {
        try {
            $this->telegram->handle();
        } catch (\Exception $e) {
            $this->logger->error('Telegram bot handling failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Получение информации о текущем webhook.
     */
    public function getWebhookInfo(): ServerResponse
    {
        return Request::getWebhookInfo();
    }

    /**
     * Получение экземпляра Telegram API клиента.
     */
    public function getClient(): Telegram
    {
        return $this->telegram;
    }

    private function addCommandsPath(): void
    {
        $this->telegram->addCommandsPath($this->commandPath);
    }

    private function configureCommands(): void
    {
        // Общие зависимости для всех команд
        $commonConfig = [
            'logger' => $this->logger,
        ];

        // Специфические зависимости для отдельных команд
        $specificConfigs = [
            'start' => [
                'messageBus' => $this->messageBus,
                'channelRepository' => $this->channelRepository,
                'telegramBotService' => $this,
            ],
        ];

        foreach ($this->telegram->getCommandsList() as $command) {
            // Объединяем общие и специфические конфиги
            $config = array_merge(
                $commonConfig,
                $specificConfigs[$command->getName()] ?? []
            );

            $this->telegram->setCommandConfig($command->getName(), $config);
        }
    }

    public function isSecretValid(string $secret): bool
    {
        return $secret === $this->secret;
    }
}

