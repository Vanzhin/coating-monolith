<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Controller\Settings;

use App\Notifications\Application\Service\SubscriptionSettingsService;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Экран настроек подписок пользователя (раздел профиля). Только авторизованный (префикс /cabinet). */
#[Route('/cabinet/notifications/settings', name: 'app_notifications_settings', methods: ['GET'])]
final class ShowSettingsAction extends AbstractController
{
    public function __construct(
        private readonly SubscriptionSettingsService $service,
        private readonly AuthUserFetcherInterface $authUserFetcher,
    ) {
    }

    public function __invoke(): Response
    {
        $types = $this->service->configurableTypesForUser(
            $this->authUserFetcher->getAuthUserId(),
            $this->isGranted('ROLE_ADMIN'),
        );

        return $this->render('cabinet/notifications/settings.html.twig', ['types' => $types]);
    }
}
