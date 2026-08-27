<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use App\Shared\Domain\Security\Role;

/**
 * Общий предикат привилегированного актора для контекстных AccessControl.
 * «Управляющий» = веб-админ ИЛИ системный принципал (консоль/крон, ROLE_SYSTEM).
 * Короткое замыкание в одном месте: появится ещё одна привилегированная роль —
 * правим здесь, а не в каждом контексте.
 */
final readonly class AccessGuard
{
    public function __construct(private AuthChecker $authChecker)
    {
    }

    public function isManager(): bool
    {
        return $this->authChecker->isGranted(Role::ROLE_ADMIN)
            || $this->authChecker->isGranted(Role::ROLE_SYSTEM);
    }
}
