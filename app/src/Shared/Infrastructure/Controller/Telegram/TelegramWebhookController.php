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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class TelegramWebhookController extends AbstractController
{
    public function __construct(
        private readonly TelegramBotService $telegramBotService,
        private readonly LoggerInterface $logger,
        private readonly QueryBusInterface $queryBus,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Webhook для обработки обновлений от Telegram Bot API.
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
            }
            if (!$this->checkIsChannelVerified($channelId)) {
                throw new BadRequestException('Telegram channel is not verified');
            }

            $this->telegramBotService->handle($secret);

            return new Response('ok');
        } catch (BadRequestException $exception) {
            $message = $this->formatErrorMessage($exception->getMessage(), $channelId);
            if ($channelId) {
                $this->telegramBotService->sendMessage((int) $channelId, $message);
            }

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

        if (null === $channelId) {
            return null;
        }
        if (!is_numeric($channelId)) {
            return null;
        }

        return (string) $channelId;
    }

    private function checkIsChannelVerified(string $channelId): bool
    {
        $query = new CheckIsChannelVerifiedQuery(channelValue: $channelId, type: ChannelType::TELEGRAM->value);

        return $this->queryBus->execute($query)->isChannelVerified;
    }

    /**
     * Форматирует сообщение об ошибке для красивого отображения в Telegram.
     */
    private function formatErrorMessage(string $errorMessage, ?string $channelId = null): string
    {
        // Маппинг ошибок на красивые сообщения
        $errorMessages = [
            'Invalid telegram bot secret' => '❌ <b>Ошибка безопасности</b>'."\n\n".'🔒 Неверный токен бота',
            'Invalid telegram channel id' => '❌ <b>Ошибка</b>'."\n\n".'⚠️ Не удалось определить идентификатор канала',
            'Telegram channel is not verified' => $this->formatChannelNotVerifiedMessage($channelId),
        ];

        // Если есть готовое сообщение - используем его
        if (isset($errorMessages[$errorMessage])) {
            return $errorMessages[$errorMessage];
        }

        // Иначе форматируем общее сообщение об ошибке
        return '❌ <b>Произошла ошибка</b>'."\n\n".
            '⚠️ '.htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8')."\n\n".
            '💡 Если проблема повторяется, обратитесь в поддержку.';
    }

    /**
     * Форматирует сообщение о том, что канал не верифицирован, со ссылкой на создание.
     */
    private function formatChannelNotVerifiedMessage(?string $channelId): string
    {
        $message = '❌ <b>Канал не верифицирован</b>'."\n\n".
            '📝 Для работы с ботом необходимо сначала верифицировать ваш Telegram канал.'."\n\n";

        if ($channelId) {
            $url = $this->generateCreationChannelUrl($channelId);
            $message .= '🔗 <a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">Создать и верифицировать канал</a>';
        } else {
            $message .= '🔗 Перейдите в личный кабинет и выполните верификацию канала.';
        }

        return $message;
    }

    private function generateCreationChannelUrl(string $channelId): string
    {
        return $this->urlGenerator->generate(
            'app_user_channel_create',
            ['value' => $channelId, 'type' => ChannelType::TELEGRAM->value],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }
}
