<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\Channel;

use App\Shared\Application\Command\CommandBusInterface;
use App\Users\Application\UseCase\Command\UnsubscribeWebPush\UnsubscribeWebPushCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Отписка от web push: удаляет все WEB_PUSH-каналы текущего юзера (на всех устройствах).
 * Только авторизованный (префикс /cabinet). Тело не требуется — владелец из аутентификации.
 */
#[Route('/cabinet/push/unsubscribe', name: 'app_push_unsubscribe', methods: ['POST'])]
final class UnsubscribeWebPushAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(): JsonResponse
    {
        $this->commandBus->execute(new UnsubscribeWebPushCommand());

        return new JsonResponse(['ok' => true]);
    }
}
