<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;
use App\Shared\Domain\Security\AuthUserFetcherInterface;

/**
 * Права на учёт обязанностей (нормы, учёт по людям). Просмотр открыт всем авторизованным —
 * read-хендлеры не гейтятся. Управление — только привилегированный актор (админ/система). Дашборд
 * owner-скоупит: админ видит всех, остальные — только свой профиль (как список отчётов).
 */
final readonly class ComplianceAccessControl
{
    public function __construct(
        private AccessGuard $guard,
        private AuthUserFetcherInterface $userFetcher,
    ) {
    }

    public function canManage(): bool
    {
        return $this->guard->isManager();
    }

    /** Админ/система видит всех на дашборде; остальные — только себя. */
    public function isManager(): bool
    {
        return $this->guard->isManager();
    }

    /** Ulid текущего актора — для резолва его профиля (owner-скоуп дашборда). */
    public function currentUserId(): string
    {
        return $this->userFetcher->getAuthUserId();
    }
}
