<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\GetUsersByIds;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Application\Service\AccessControl\AuditAccessControl;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Users\Application\DTO\UserSuggestDTO;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Repository\UserRepositoryInterface;

/**
 * Гидрация чипов фасета «Актор» отдаёт email юзеров — доступ только управляющему,
 * как и сам журнал (см. AuditAccessControl).
 */
readonly class GetUsersByIdsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private AuditAccessControl $access,
    ) {
    }

    public function __invoke(GetUsersByIdsQuery $query): GetUsersByIdsQueryResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $users = $this->userRepository->findByIds($query->ids);

        return new GetUsersByIdsQueryResult(array_map($this->toSuggestDto(...), $users));
    }

    private function toSuggestDto(User $user): UserSuggestDTO
    {
        return new UserSuggestDTO($user->getUlid(), (string) $user->getEmail());
    }
}
