<?php

declare(strict_types=1);

namespace App\Documents\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;

/**
 * Права на документы (поисковый индекс). Просмотр/поиск открыт всем авторизованным.
 * Массовая запись в индекс (bulk-insert) — только привилегированный актор
 * (админ или системный принципал консоли). Единый источник истины для всех адаптеров.
 */
final readonly class DocumentAccessControl
{
    public function __construct(private AccessGuard $guard)
    {
    }

    public function canManage(): bool
    {
        return $this->guard->isManager();
    }
}
