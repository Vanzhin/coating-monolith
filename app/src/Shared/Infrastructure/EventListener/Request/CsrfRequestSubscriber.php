<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener\Request;

use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Глобальная CSRF-защита мутаций на cookie-firewall (main).
 *
 * Любой небезопасный метод (POST/PUT/PATCH/DELETE) на веб-firewall обязан нести валидный
 * CSRF-токен (intention 'mutation') — в скрытом поле `_csrf_token` или заголовке `X-CSRF-TOKEN`.
 * Так новые мутирующие роуты защищены по умолчанию (fail-safe), а не «пока кто-то не забыл токен».
 *
 * НЕ трогаем:
 *  - безопасные методы (GET/HEAD/OPTIONS/TRACE);
 *  - stateless JWT-firewall `^/api` — там нет cookie, CSRF неприменим;
 *  - роуты со своей CSRF-защитой (Symfony Form / LoginFormAuthenticator) и внешние/pre-auth
 *    (вебхук, login/logout/login-link/sign-up) — список EXEMPT_ROUTES.
 */
final readonly class CsrfRequestSubscriber implements EventSubscriberInterface
{
    /** Единый intention всех «мутационных» форм и AJAX приложения. */
    public const INTENTION = 'mutation';

    /**
     * Роуты, которые управляют CSRF сами или не должны его требовать.
     *  - app_login: LoginFormAuthenticator валидирует токен 'authenticate';
     *  - app_sign_up / app_user_channel_create / app_user_channel_verification: Symfony Form CSRF;
     *  - app_login_link / app_login_by_link / app_logout: pre-auth/служебные auth-флоу;
     *  - app_telegram_webhook: внешний вызов Telegram, без cookie и без токена.
     */
    private const EXEMPT_ROUTES = [
        'app_login',
        'app_logout',
        'app_login_link',
        'app_login_by_link',
        'app_sign_up',
        'app_user_channel_create',
        'app_user_channel_verification',
        'app_telegram_webhook',
    ];

    public function __construct(private CsrfTokenManagerInterface $csrf)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // После роутинга (priority 32 — уже есть _route), до контроллера.
        return [KernelEvents::REQUEST => ['onRequest', 9]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();

        if ($request->isMethodSafe()) {
            return;
        }
        if (str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $route = $request->attributes->get('_route');
        // Нет совпавшего роута (404/служебное) — пусть отработает штатно, не мешаем.
        if (null === $route || \in_array($route, self::EXEMPT_ROUTES, true)) {
            return;
        }

        $token = $request->headers->get('X-CSRF-TOKEN') ?? $request->request->get('_csrf_token');
        if (!\is_string($token) || !$this->csrf->isTokenValid(new CsrfToken(self::INTENTION, $token))) {
            throw new ForbiddenException('Недействительный CSRF-токен. Обновите страницу и попробуйте снова.');
        }
    }
}
