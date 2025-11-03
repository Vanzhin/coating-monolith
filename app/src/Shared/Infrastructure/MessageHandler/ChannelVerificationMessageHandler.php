<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\MessageHandler;

use App\Notifications\Application\Service\Telegram\TelegramBotService;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Message\ChannelVerificationMessage;
use App\Users\Application\UseCase\Command\VerifyChannel\VerifyChannelCommand;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use App\Users\Domain\Service\TokenServiceInterface;
use Psr\Log\LoggerInterface;

readonly class ChannelVerificationMessageHandler
{
    public function __construct(
        private ChannelRepositoryInterface $channelRepository,
        private TokenServiceInterface $tokenService,
        private CommandBusInterface $commandBus,
        private TelegramBotService $telegramBotService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ChannelVerificationMessage $message): void
    {
        try {
            $channel = $this->channelRepository->findById($message->channelId);

            if (!$channel) {
                $this->sendErrorMessage($message->telegramChatId, 'Канал не найден.');
                return;
            }

            if ($channel->isVerified()) {
                $this->sendErrorMessage($message->telegramChatId, 'Канал уже верифицирован.');
                return;
            }

            // Проверяем токен для этого канала
            try {
                $this->tokenService->verifySubjectByTokenString($message->tokenString, $channel);
            } catch (\Exception $e) {
                $this->sendErrorMessage($message->telegramChatId, 'Код верификации недействителен или истек срок действия.');
                return;
            }

            // Верифицируем канал
            $command = new VerifyChannelCommand(
                channelId: $channel->getId(),
                tokenString: $message->tokenString
            );
            $this->commandBus->execute($command);

            $this->sendSuccessMessage($message->telegramChatId, '✅ Канал успешно верифицирован!');
        } catch (\Exception $e) {
            $this->logger->error('Channel verification failed', [
                'error' => $e->getMessage(),
                'channelId' => $message->channelId,
                'chatId' => $message->telegramChatId,
            ]);
            $this->sendErrorMessage($message->telegramChatId, 'Произошла ошибка при обработке верификации.');
        }
    }

    private function sendSuccessMessage(int $chatId, string $text): void
    {
        try {
            $this->telegramBotService->sendMessage($chatId, $text);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send success message', [
                'chatId' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendErrorMessage(int $chatId, string $text): void
    {
        try {
            $this->telegramBotService->sendMessage($chatId, '❌ ' . $text);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send error message', [
                'chatId' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

