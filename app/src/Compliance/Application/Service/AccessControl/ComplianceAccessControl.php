<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;

/**
 * Права на учёт обязанностей (нормы, учёт по людям). Просмотр открыт всем авторизованным —
 * read-хендлеры не гейтятся. Управление — только привилегированный актор (админ/система).
 */
final readonly class ComplianceAccessControl
{
    public function __construct(private AccessGuard $guard)
    {
    }

    public function canManage(): bool
    {
        return $this->guard->isManager();
    }
}
