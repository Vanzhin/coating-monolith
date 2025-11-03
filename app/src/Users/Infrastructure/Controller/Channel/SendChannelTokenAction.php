<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\Channel;

use App\Shared\Application\Security\ResponseFormatter;
use App\Shared\Infrastructure\Service\NotifierFactory;
use App\Users\Application\Service\AccessControl\ChannelAccessControl;
use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use App\Users\Domain\Service\TokenServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SendChannelTokenAction extends AbstractController
{
    public function __construct(
        private readonly TokenServiceInterface $tokenService,
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly ChannelAccessControl $channelAccessControl,
        private readonly ResponseFormatter $responseFormatter,
        private readonly NotifierFactory $factory,
    ) {
    }

    #[Route('user/channel/verification/send-token', name: 'app_user_channel_verification_send_token', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->json(
                $this->responseFormatter->formatError('Пользователь не авторизован', Response::HTTP_UNAUTHORIZED),
                Response::HTTP_UNAUTHORIZED
            );
        }

        $data = json_decode($request->getContent(), true);
        $channelId = $data['channel_id'] ?? null;

        if (!$channelId) {
            return $this->json(
                $this->responseFormatter->formatError('Канал не указан', Response::HTTP_BAD_REQUEST),
                Response::HTTP_BAD_REQUEST
            );
        }

        $channel = $this->channelRepository->find($channelId);
        if (!$channel) {
            return $this->json(
                $this->responseFormatter->formatError('Канал не найден', Response::HTTP_NOT_FOUND),
                Response::HTTP_NOT_FOUND
            );
        }

        /** @var Channel $channel */
        if (!$this->channelAccessControl->canView($channel)) {
            return $this->json(
                $this->responseFormatter->formatError('Запрещено', Response::HTTP_FORBIDDEN),
                Response::HTTP_FORBIDDEN
            );
        }

        if ($channel->isVerified()) {
            return $this->json(
                $this->responseFormatter->formatError('Канал уже верифицирован', Response::HTTP_BAD_REQUEST),
                Response::HTTP_BAD_REQUEST
            );
        }

        $cooldownRemaining = $this->tokenService->getTimeUntilNextToken($channel);
        if ($cooldownRemaining > 0) {
            $minutes = ceil($cooldownRemaining / 60);
            return $this->json(
                $this->responseFormatter->formatError(
                    "Повторная отправка возможна через {$minutes} минут",
                    Response::HTTP_BAD_REQUEST,
                    ['cooldown_remaining' => $cooldownRemaining]
                ),
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $token = $this->tokenService->makeToken($channel);
            $notifier = $this->factory->create($channel->getType());

            $notifier->sendVerificationCode($channel, $token->getToken(), $token->getRemainingTimeInSeconds());
            return $this->json(
                $this->responseFormatter->formatSuccess(
                    'Код верификации отправлен!',
                    [
                        'cooldown_remaining' => $token->getRemainingTimeInSeconds(),
                        'message_type' => 'success'
                    ]
                )
            );
        } catch (\Exception $e) {
            return $this->json(
                $this->responseFormatter->formatError(
                    'Ошибка при отправке кода: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                ),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}