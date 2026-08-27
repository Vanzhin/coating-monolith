<?php

declare(strict_types=1);

namespace App\Certificates\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;

/**
 * Права на документы. Просмотр (список/превью/скачивание) открыт всем авторизованным —
 * поэтому read-хендлеры не гейтятся. Управление (создание/правка/удаление) — только
 * привилегированный актор (админ или системный принципал консоли).
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
