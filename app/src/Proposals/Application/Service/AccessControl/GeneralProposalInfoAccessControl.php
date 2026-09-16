<?php

declare(strict_types=1);

namespace App\Proposals\Application\Service\AccessControl;

use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Shared\Application\Security\AccessGuard;
use App\Shared\Domain\Security\AuthUserFetcherInterface;

/**
 * Права на форму КП. Resource-based: в проверку передаётся УЖЕ ЗАГРУЖЕННЫЙ агрегат, «кто актор»
 * берётся из аутентификации (AuthUserFetcher), а НЕ от вызывающего — иначе гейт тавтологичен
 * (owner ресурса сравнивается сам с собой и всегда проходит). Доступ имеет управляющий
 * (админ/системный принципал) ИЛИ владелец формы.
 */
readonly class GeneralProposalInfoAccessControl
{
    public function __construct(
        private AccessGuard $accessGuard,
        private AuthUserFetcherInterface $authUserFetcher,
    ) {
    }

    public function canView(GeneralProposalInfo $proposalInfo): bool
    {
        return $this->isOwnerOrManager($proposalInfo);
    }

    public function canEdit(GeneralProposalInfo $proposalInfo): bool
    {
        return $this->isOwnerOrManager($proposalInfo);
    }

    private function isOwnerOrManager(GeneralProposalInfo $proposalInfo): bool
    {
        // Короткое замыкание: управляющему (в т.ч. системному принципалу консоли) владение не сверяем,
        // getAuthUserId() для него не вызывается — важно, у консоли нет залогиненного юзера.
        return $this->accessGuard->isManager()
            || $proposalInfo->isOwnedBy($this->authUserFetcher->getAuthUserId());
    }
}
