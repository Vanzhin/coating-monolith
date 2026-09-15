<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Controller;

use App\Notifications\Application\UseCase\Command\MarkNotificationsRead\MarkNotificationsReadCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Пометить уведомления текущего пользователя прочитанными (фронт бьёт при открытии приложения →
 * сбрасывает бейдж). Только авторизованный (префикс /cabinet). Владелец — из аутентификации.
 */
#[Route('/cabinet/notifications/read', name: 'app_notifications_read', methods: ['POST'])]
final class MarkReadAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly AuthUserFetcherInterface $authUserFetcher,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $this->commandBus->execute(new MarkNotificationsReadCommand($this->authUserFetcher->getAuthUserId()));

        return new JsonResponse(['ok' => true]);
    }
}
