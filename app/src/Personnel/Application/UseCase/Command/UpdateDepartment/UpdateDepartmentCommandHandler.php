<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\UpdateDepartment;

use App\Personnel\Application\Service\AccessControl\PersonnelAccessControl;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class UpdateDepartmentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DepartmentRepositoryInterface $repository,
        private PersonnelAccessControl $access,
    ) {
    }

    public function __invoke(UpdateDepartmentCommand $command): UpdateDepartmentCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $department = $this->repository->findOneById($command->id);
        if (null === $department) {
            throw new AppException('Отдел не найден.', Response::HTTP_NOT_FOUND);
        }

        $department->rename($command->title);
        $this->repository->add($department);

        return new UpdateDepartmentCommandResult($department->getId(), $department->getTitle());
    }
}
