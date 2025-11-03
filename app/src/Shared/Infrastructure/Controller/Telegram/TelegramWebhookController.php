<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller\Telegram;

use App\Notifications\Application\Service\Telegram\TelegramBotService;
use App\Shared\Application\Query\QueryBusInterface;
use App\Users\Application\UseCase\Query\CheckIsChannelVerified\CheckIsChannelVerifiedQuery;
use App\Users\Domain\Entity\ChannelType;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TelegramWebhookController extends AbstractController
{
    public function __construct(
        private readonly TelegramBotService $telegramBotService,
        private readonly LoggerInterface $logger,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    /**
     * Webhook для обработки обновлений от Telegram Bot API
     */
    #[Route('/webhook/telegram', name: 'app_telegram_webhook', methods: ['POST'])]
    public function webhook(Request $request): Response
    {
        try {
            $secret = $request->headers->get('x-telegram-bot-api-secret-token');
            if (!$secret && !$this->telegramBotService->isSecretValid($secret)) {
                throw new BadRequestException('Invalid telegram bot secret');
            }
            $channelId = $this->getChannelId($request);
            if (!$channelId) {
                throw new BadRequestException('Invalid telegram channel id');
            };
            if (!$this->checkIsChannelVerified($channelId)) {
                $this->telegramBotService->sendMessage((int)$channelId, 'Telegram channel is not verified');
                throw new BadRequestException('Telegram channel is not verified');
            }

            $this->telegramBotService->handle($secret);

            return new Response('ok');
        } catch (\Exception $e) {
            // Логируем ошибку, но возвращаем 200, чтобы Telegram не повторял запрос
            $this->logger->error('Telegram webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new Response('ok');
        }
    }

    private function getChannelId(Request $request): ?string
    {
        $messageData = $request->getPayload()->all();
        $channelId = $messageData['message']['from']['id'] ?? null;

        if ($channelId === null) {
            return null;
        }
        if (!is_numeric($channelId)) {
            return null;
        }

        return (string)$channelId;
    }

    private function checkIsChannelVerified(string $channelId): bool
    {
        $query = new CheckIsChannelVerifiedQuery(channelId: $channelId, type: ChannelType::TELEGRAM->value);

        return $this->queryBus->execute($query)->isChannelVerified;
    }


}

