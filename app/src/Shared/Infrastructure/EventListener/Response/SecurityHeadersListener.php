<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener\Response;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Security-заголовки на все ответы. Защита от clickjacking (X-Frame-Options/frame-ancestors),
 * MIME-sniffing (nosniff), утечки реферера. CSP — пока в Report-Only: браузер логирует нарушения,
 * но не блокирует, чтобы обкатать политику без риска сломать Bootstrap/Stimulus/Encore и inline
 * FOUC-скрипт темы. После проверки в проде (ноль нарушений) — переключить на Content-Security-Policy.
 * Все ассеты same-origin (Encore, Bootstrap Icons бандлятся локально), поэтому 'self' достаточно;
 * 'unsafe-inline' нужен ровно для inline-скрипта темы и inline-стилей Bootstrap.
 */
class SecurityHeadersListener
{
    private const CSP = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data:; "
        ."font-src 'self' data:; "
        ."connect-src 'self'; "
        ."frame-ancestors 'none'; "
        ."base-uri 'self'; "
        ."form-action 'self'";

    #[AsEventListener]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Strict-Transport-Security', 'max-age=31536000');
        $headers->set('Content-Security-Policy-Report-Only', self::CSP);
    }
}
