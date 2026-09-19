<?php

declare(strict_types=1);

namespace App\Reports\Application\Service\AccessControl;

use App\Reports\Domain\Aggregate\Report\Report;
use App\Shared\Application\Security\AccessGuard;
use App\Shared\Domain\Security\AuthUserFetcherInterface;

/**
 * Доступ к отчёту — owner-based: любой авторизованный заводит отчёт (становится владельцем),
 * правит только свой; админ (isManager) — любой. Ревьюер утверждает/отклоняет (отдельно, позже).
 */
final readonly class ReportAccessControl
{
    public function __construct(
        private AccessGuard $guard,
        private AuthUserFetcherInterface $userFetcher,
    ) {
    }

    /** Id текущего актора — владелец создаваемого отчёта. */
    public function currentUserId(): string
    {
        return $this->userFetcher->getAuthUserId();
    }

    /** Правка/просмотр: владелец или админ. */
    public function canEdit(Report $report): bool
    {
        return $this->guard->isManager() || $report->isOwnedBy($this->userFetcher->getAuthUserId());
    }

    /** Проверка (утвердить/отклонить): админ или назначенный ревьюер. */
    public function canReview(Report $report): bool
    {
        return $this->guard->isManager() || $report->getReviewerId() === $this->userFetcher->getAuthUserId();
    }
}
