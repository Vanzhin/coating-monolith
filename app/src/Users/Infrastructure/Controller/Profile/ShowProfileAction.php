<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\Profile;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Профиль — личный хаб пользователя (аккаунт + меню разделов). Сейчас единственный раздел —
 * «Уведомления»; профиль расширяется добавлением строк меню (контакты, смена пароля — позже).
 * Только авторизованный (префикс /cabinet). Счётчик непрочитанного в меню — twig-функция
 * unread_notifications_count().
 */
#[Route('/cabinet/profile', name: 'app_cabinet_profile', methods: ['GET'])]
final class ShowProfileAction extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('cabinet/profile/index.html.twig');
    }
}
