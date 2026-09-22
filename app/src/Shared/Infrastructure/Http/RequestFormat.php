<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Единая точка определения формата запроса — чтобы обработчики ошибок (ExceptionListener,
 * MutationErrorListener) не разъезжались в логике «ждёт JSON / это HTML-страница».
 */
final class RequestFormat
{
    /** Клиент ждёт JSON: AJAX, Accept с application/json или согласованный формат json. */
    public static function expectsJson(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }
        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return true;
        }

        return 'json' === $request->getPreferredFormat('');
    }

    /** Обычная HTML-страница (не JSON): браузерная навигация/сабмит формы. */
    public static function isHtml(Request $request): bool
    {
        if (self::expectsJson($request)) {
            return false;
        }
        $accept = (string) $request->headers->get('Accept');

        return '' === $accept
            || str_contains($accept, 'text/html')
            || str_contains($accept, '*/*')
            || 'html' === $request->getPreferredFormat('');
    }
}
