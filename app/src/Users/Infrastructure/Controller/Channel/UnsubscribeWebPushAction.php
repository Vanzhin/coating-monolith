<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\Channel;

use App\Shared\Application\Command\CommandBusInterface;
use App\Users\Application\UseCase\Command\UnsubscribeWebPush\UnsubscribeWebPushCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Отписка от web push: удаляет WEB_PUSH-канал текущего устройства (по endpoint из тела).
 * Только авторизованный (префикс /cabinet). Тело — { endpoint } подписки, которую гасим;
 * владелец из аутентификации. Нет endpoint — отписка no-op.
 */
#[Route('/cabinet/push/unsubscribe', name: 'app_push_unsubscribe', methods: ['POST'])]
final class UnsubscribeWebPushAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        $endpoint = \is_array($payload) && \is_string($payload['endpoint'] ?? null) ? $payload['endpoint'] : '';

        $this->commandBus->execute(new UnsubscribeWebPushCommand($endpoint));

        return new JsonResponse(['ok' => true]);
    }
}
