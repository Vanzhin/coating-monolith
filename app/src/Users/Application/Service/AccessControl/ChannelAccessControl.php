<?php

declare(strict_types=1);

namespace App\Users\Application\Service\AccessControl;

use App\Shared\Application\Security\AuthChecker;
use App\Shared\Domain\Security\Role;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Users\Domain\Entity\Channel;

/**
 * Служба проверки прав доступа к каналу
 */
readonly class ChannelAccessControl
{
    public function __construct(
        private AuthChecker $checker,
        private AuthUserFetcherInterface $fetcher,
    )
    {
    }

    public function canView(Channel $channel): bool
    {
        $userId = $this->fetcher->getAuthUser()->getUlid();
        if ($channel->getOwner()->getUlid() === $userId) {
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
