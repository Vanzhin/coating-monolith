<?php

declare(strict_types=1);

namespace App\Shared\Domain\Security;

/**
 * Роль пользователя.
 */
class Role
{
    public const ROLE_USER = 'ROLE_USER';
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    // Синтетическая роль консоли/крона: у CLI нет залогиненного юзера, а авторизацию
    // операций делают AccessControl'ы в хендлерах. Ставится только консольным
    // подписчиком (см. SystemUser), в web недостижима.
    public const ROLE_SYSTEM = 'ROLE_SYSTEM';

    public const ROLES = [
        self::ROLE_USER,
        self::ROLE_ADMIN,
        self::ROLE_SYSTEM,
    ];
}
