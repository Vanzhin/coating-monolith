<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Проверка CSRF-токена для мутирующих экшенов (Symfony 7.0 — атрибута #[IsCsrfTokenValid] ещё нет).
 * Единая точка: экшен зовёт assertValid перед выполнением команды; при несовпадении — ForbiddenException
 * (403). Токен рендерится в форме через csrf_token('<intention>'), сюда приходит из тела запроса.
 */
final readonly class CsrfGuard
{
    public function __construct(
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function assertValid(string $intention, ?string $token): void
    {
        if (null === $token || !$this->csrfTokenManager->isTokenValid(new CsrfToken($intention, $token))) {
            throw new ForbiddenException('Недействительный CSRF-токен. Обновите страницу и повторите.');
        }
    }
}
