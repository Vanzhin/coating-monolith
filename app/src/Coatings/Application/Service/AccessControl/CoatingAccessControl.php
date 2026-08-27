<?php

declare(strict_types=1);

namespace App\Coatings\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;

/**
 * Права на каталог покрытий (покрытия, системы, слои, подготовка поверхности, цвета,
 * теги, производители). Просмотр открыт всем авторизованным — read-хендлеры не гейтятся.
 * Управление (любая мутация) — только привилегированный актор (админ или система консоли).
 * Владения по пользователю у каталога нет; появится — добавим resource-based методы
 * (см. канон в CLAUDE.md).
 */
final readonly class CoatingAccessControl
{
    public function __construct(private AccessGuard $guard)
    {
    }

    public function canManage(): bool
    {
        return $this->guard->isManager();
    }
}
