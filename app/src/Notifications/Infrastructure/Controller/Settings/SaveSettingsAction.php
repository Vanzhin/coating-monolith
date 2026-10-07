<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Controller\Settings;

use App\Notifications\Application\Service\SubscriptionSettingsService;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Сохранение подписки одного события (у каждого события своя кнопка). */
#[Route('/cabinet/notifications/settings', name: 'app_notifications_settings_save', methods: ['POST'])]
final class SaveSettingsAction extends AbstractController
{
    public function __construct(
        private readonly SubscriptionSettingsService $service,
        private readonly AuthUserFetcherInterface $authUserFetcher,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $type = NotificationType::tryFrom((string) $request->request->get('type', ''));
        if (null === $type) {
            throw new AppException('Неизвестный тип уведомления.');
        }

        /** @var array<string, mixed> $channels */
        $channels = $request->request->all('channels');

        $this->service->saveType(
            $this->authUserFetcher->getAuthUserId(),
            $this->isGranted('ROLE_ADMIN'),
            $type,
            $channels,
        );

        $this->addFlash('success', 'Настройки сохранены.');

        return $this->redirectToRoute('app_notifications_settings');
    }
}
