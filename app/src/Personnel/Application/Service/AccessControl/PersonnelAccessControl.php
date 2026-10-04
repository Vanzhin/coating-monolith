<?php

declare(strict_types=1);

namespace App\Personnel\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;

/**
 * Права на персонал (должности, отделы, профили). Просмотр открыт всем авторизованным —
 * read-хендлеры не гейтятся. Управление (создание/правка справочников и профилей) —
 * только привилегированный актор (админ/система).
 */
final readonly class PersonnelAccessControl
{
    public function __construct(private AccessGuard $guard)
    {
    }

    public function canManage(): bool
    {
        return $this->guard->isManager();
    }
}
