<?php

declare(strict_types=1);

namespace App\Certificates\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;

/**
 * Права на организации-издатели. Управление (создание/правка/удаление) — только
 * привилегированный актор (админ/система). Просмотр не гейтим на уровне запроса:
 * названия организаций уже видны всем авторизованным в фасете фильтра документов
 * (GetPagedIssuers переиспользуется списком документов).
 */
final readonly class IssuerAccessControl
{
    public function __construct(private AccessGuard $guard)
    {
    }

    public function canManage(): bool
    {
        return $this->guard->isManager();
    }
}
