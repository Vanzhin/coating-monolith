<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;
use App\Shared\Domain\Security\AuthUserFetcherInterface;

/**
 * Доступ к запросам — capability, только привилегированный актор (админ или система консоли):
 * создать, одобрить, смотреть очередь. Владения по пользователю нет — ownerId хранится как «кто
 * инициировал» (аудит/адресат ответа), на доступ не влияет.
 */
final readonly class InquiryAccessControl
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

    /** Id текущего актора — владелец создаваемого запроса / кто одобрил. */
    public function currentUserId(): string
    {
        return $this->userFetcher->getAuthUserId();
    }
}
