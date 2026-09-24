<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\Profile;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Настройки профиля — редкие переключатели, собранные в одном месте (push-уведомления, тема,
 * позже — контакты/безопасность и т.п.), чтобы не занимать место в основных разделах. Только
 * авторизованный (префикс /cabinet).
 */
#[Route('/cabinet/profile/settings', name: 'app_cabinet_profile_settings', methods: ['GET'])]
final class ProfileSettingsAction extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('cabinet/profile/settings.html.twig');
    }
}
