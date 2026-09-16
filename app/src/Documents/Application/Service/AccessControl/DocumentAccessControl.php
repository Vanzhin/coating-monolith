<?php

declare(strict_types=1);

namespace App\Documents\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;

/**
 * Права на управление документами (ES-индекс). Capability-based (ресурса-владельца нет):
 * массовые операции над индексом — только управляющий (админ/системный принципал). Единый
 * гейт для всех адаптеров (HTTP/API/консоль); контекст Documents раньше не имел авторизации.
 */
readonly class DocumentAccessControl
{
    public function __construct(
        private AccessGuard $accessGuard,
    ) {
    }

    public function canManage(): bool
    {
        return $this->accessGuard->isManager();
    }
}
