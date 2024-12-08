<?php

declare(strict_types=1);

namespace App\Proposals\Application\Service\AccessControl;

use App\Proposals\Domain\Repository\GeneralProposalInfoRepositoryInterface;
use App\Shared\Application\Security\AuthChecker;
use App\Shared\Domain\Security\Role;
use App\Shared\Domain\Service\AssertService;

/**
 * Служба проверки прав доступа к формам
 */
readonly class GeneralProposalInfoAccessControl
{
    public function __construct(
        private AuthChecker                            $authChecker,
        private GeneralProposalInfoRepositoryInterface $generalProposalInfoRepository,
    )
    {
    }

    /**
     * Может ли пользователь удалить форму?
     */
    public function canDeleteGeneralProposalInfo(string $userId, string $proposalInfoId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        $proposalInfo = $this->generalProposalInfoRepository->findOneById($proposalInfoId);
        AssertService::notNull($proposalInfo, sprintf('Форма с идентификатором %s не найдена.', $proposalInfoId));

        return $proposalInfo->isOwnedBy($userId);
    }

    /**
     * Может ли пользователь изменить форму?
     */
    public function canUpdateGeneralProposalInfo(string $userId, string $proposalInfoId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        $proposalInfo = $this->generalProposalInfoRepository->findOneById($proposalInfoId);
        AssertService::notNull($proposalInfo, sprintf('Форма с идентификатором %s не найдена.', $proposalInfoId));

        return $proposalInfo->isOwnedBy($userId);
    }


    private function isAdmin(): bool
    {
        return $this->authChecker->isGranted(Role::ROLE_ADMIN);
    }
}
