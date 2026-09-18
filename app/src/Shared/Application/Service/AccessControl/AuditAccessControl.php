<?php

declare(strict_types=1);

namespace App\Shared\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;

/**
 * Права на журнал аудита (вкладка «История» по объекту и класс-журнал изменений).
 * Владения по пользователю у записи аудита нет — просмотр закрыт для всех, кроме
 * управляющего (админ или системный принципал консоли/крона).
 */
final readonly class AuditAccessControl
{
    public function __construct(private AccessGuard $accessGuard)
    {
    }

    public function canManage(): bool
    {
        return $this->accessGuard->isManager();
    }
}
