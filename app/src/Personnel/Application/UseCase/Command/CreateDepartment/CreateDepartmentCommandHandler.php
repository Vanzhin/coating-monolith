<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\CreateDepartment;

use App\Personnel\Application\Service\AccessControl\PersonnelAccessControl;
use App\Personnel\Domain\Aggregate\Department\Department;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Personnel\Domain\Service\DepartmentTreePolicy;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\ForbiddenException;

final readonly class CreateDepartmentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DepartmentRepositoryInterface $repository,
        private DepartmentTreePolicy $treePolicy,
        private PersonnelAccessControl $access,
    ) {
    }

    public function __invoke(CreateDepartmentCommand $command): CreateDepartmentCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        // Валидность title и родителя (та же компания, без циклов) проверяет сам агрегат
        // через политику в конструкторе.
        $department = new Department(
            UuidService::generate(),
            $command->title,
            $command->companyId,
            $command->parentId,
            $command->headUserUlid,
            $this->treePolicy,
        );
        $this->repository->add($department);

        return new CreateDepartmentCommandResult($department->getId(), $department->getTitle(), $department->getCompanyId());
    }
}
