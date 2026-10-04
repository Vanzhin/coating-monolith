<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\MoveDepartment;

use App\Personnel\Application\Service\AccessControl\PersonnelAccessControl;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Personnel\Domain\Service\DepartmentTreePolicy;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Перенос узла в другого родителя (или в корень — parentId null). Валидность нового
 * родителя (та же компания, без циклов) проверяет DepartmentTreePolicy внутри moveTo.
 */
final readonly class MoveDepartmentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DepartmentRepositoryInterface $repository,
        private DepartmentTreePolicy $treePolicy,
        private PersonnelAccessControl $access,
    ) {
    }

    public function __invoke(MoveDepartmentCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $department = $this->repository->findOneById($command->id);
        if (null === $department) {
            throw new AppException('Отдел не найден.', Response::HTTP_NOT_FOUND);
        }

        $department->moveTo($command->parentId, $this->treePolicy);
        $this->repository->add($department);
    }
}
