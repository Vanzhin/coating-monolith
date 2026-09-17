<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\SearchUsers;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Application\Service\AccessControl\AuditAccessControl;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Users\Application\DTO\UserSuggestDTO;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Repository\UserRepositoryInterface;

/**
 * Suggest-эндпоинт отдаёт email юзеров — доступен только управляющему
 * (см. AuditAccessControl), как и сам журнал, который он кормит.
 */
readonly class SearchUsersQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private AuditAccessControl $access,
    ) {
    }

    public function __invoke(SearchUsersQuery $query): SearchUsersQueryResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $users = $this->userRepository->searchByEmail($query->q, $query->limit);

        return new SearchUsersQueryResult(array_map($this->toSuggestDto(...), $users));
    }

    private function toSuggestDto(User $user): UserSuggestDTO
    {
        return new UserSuggestDTO($user->getUlid(), (string) $user->getEmail());
    }
}
