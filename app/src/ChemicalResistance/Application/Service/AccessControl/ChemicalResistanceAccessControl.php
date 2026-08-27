<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;

/**
 * Права на химстойкость (вещества и оценки покрытий). Просмотр открыт всем авторизованным
 * (химсекция видна в превью покрытия) — read-хендлеры не гейтятся. Управление (создание/
 * правка/удаление веществ и оценок) — только привилегированный актор (админ/система).
 */
final readonly class ChemicalResistanceAccessControl
{
    public function __construct(private AccessGuard $guard)
    {
    }

    public function canManage(): bool
    {
        return $this->guard->isManager();
    }
}
