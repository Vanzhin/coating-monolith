<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Service;

use App\Notifications\Domain\Service\SubjectContextProviderInterface;
use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;

/**
 * Реализация порта контекста субъекта на стороне Personnel (владелец профилей/отделов). Профиль → его userUlid
 * и начальник его отдела (headUserUlid) — через собственные query/репозиторий контекста, без новых запросов.
 */
final readonly class PersonnelSubjectContextProvider implements SubjectContextProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private DepartmentRepositoryInterface $departments,
    ) {
    }

    public function userUlidOfProfile(string $profileId): ?string
    {
        return $this->profile($profileId)?->userUlid;
    }

    public function departmentHeadUlidOfProfile(string $profileId): ?string
    {
        $departmentId = $this->profile($profileId)?->departmentId;
        if (null === $departmentId) {
            return null;
        }

        return $this->departments->findOneById($departmentId)?->getHeadUserUlid();
    }

    private function profile(string $profileId): ?ProfileDTO
    {
        /** @var GetProfileQueryResult $result */
        $result = $this->queryBus->execute(new GetProfileQuery($profileId));

        return $result->profile;
    }
}
