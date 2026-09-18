<?php

declare(strict_types=1);

namespace App\Reports\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;

/**
 * Права на справочник проектов. Управление — только привилегированный актор (админ/система).
 * Просмотр не гейтим на уровне запроса (переиспользуется формой отчёта).
 */
final readonly class ProjectAccessControl
{
    public function __construct(private AccessGuard $guard)
    {
    }

    public function canManage(): bool
    {
        return $this->guard->isManager();
    }
}
