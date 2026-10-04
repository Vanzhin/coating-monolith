<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\AssignDepartmentHead;

use App\Personnel\Application\Service\AccessControl\PersonnelAccessControl;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class AssignDepartmentHeadCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DepartmentRepositoryInterface $repository,
        private PersonnelAccessControl $access,
    ) {
    }

    public function __invoke(AssignDepartmentHeadCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $department = $this->repository->findOneById($command->id);
        if (null === $department) {
            throw new AppException('Отдел не найден.', Response::HTTP_NOT_FOUND);
        }

        $department->assignHead($command->headUserUlid);
        $this->repository->add($department);
    }
}
