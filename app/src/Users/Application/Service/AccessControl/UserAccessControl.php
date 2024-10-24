<?php

declare(strict_types=1);

namespace App\Users\Application\Service\AccessControl;

use App\Shared\Application\Security\AuthChecker;
use App\Shared\Domain\Security\Role;

/**
 * Служба проверки прав доступа к пользователям
 */
readonly class UserAccessControl
{
    public function __construct(private AuthChecker $checker)
    {
    }

    public function canView(string $requestedUserId, string $userId): bool
    {
        if ($requestedUserId === $userId) {
            return true;
        }

        if (!$this->isAdmin()) {
            return false;
        }

        return true;
    }

    private function isAdmin(): bool
    {
        return $this->checker->isGranted(Role::ROLE_ADMIN);
    }
}
