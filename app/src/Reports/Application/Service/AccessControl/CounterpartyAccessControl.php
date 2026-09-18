<?php

declare(strict_types=1);

namespace App\Reports\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;

/**
 * Права на справочник контрагентов. Управление (создание/правка/удаление) — только
 * привилегированный актор (админ/система). Просмотр не гейтим на уровне запроса —
 * suggest/список переиспользуются формой отчёта; ограничиваем страницы-управление на контроллере.
 */
final readonly class CounterpartyAccessControl
{
    public function __construct(private AccessGuard $guard)
    {
    }

    public function canManage(): bool
    {
        return $this->guard->isManager();
    }
}
