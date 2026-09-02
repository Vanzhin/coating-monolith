<?php

declare(strict_types=1);

namespace App\Proposals\Application\Service\AccessControl;

use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Shared\Application\Security\AccessGuard;
use App\Shared\Domain\Security\AuthUserFetcherInterface;

/**
 * Служба проверки прав доступа к формам КП.
 *
 * Править/удалять/скачивать/клонировать форму вправе её владелец ИЛИ управляющий (админ/система).
 * «Кто текущий актор» берём из AuthUserFetcher, а НЕ из параметра/ресурса — иначе сравнение
 * вырождается в owner === owner и пропускает любого. Проверке передаём уже загруженный агрегат
 * (без повторного фетча по id — так нет двойного запроса и TOCTOU).
 */
readonly class GeneralProposalInfoAccessControl
{
    public function __construct(
        private AccessGuard $guard,
        private AuthUserFetcherInterface $fetcher,
    ) {
    }

    public function canEdit(GeneralProposalInfo $proposal): bool
    {
        return $this->guard->isManager() || $proposal->isOwnedBy($this->fetcher->getAuthUserId());
    }
}
