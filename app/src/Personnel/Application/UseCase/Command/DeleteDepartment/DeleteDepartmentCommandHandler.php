<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\DeleteDepartment;

use App\Personnel\Application\Service\AccessControl\PersonnelAccessControl;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Целостность самого дерева (узел с детьми нельзя удалить, пока их не перенесли/удалили) —
 * внутридеревесный инвариант, не про использование в других агрегатах, поэтому проверяется
 * отдельно от guard'а «отдел используется в профилях».
 */
final readonly class DeleteDepartmentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DepartmentRepositoryInterface $repository,
        private ProfileRepositoryInterface $profileRepository,
        private PersonnelAccessControl $access,
    ) {
    }

    public function __invoke(DeleteDepartmentCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $department = $this->repository->findOneById($command->id);
        if (null === $department) {
            throw new AppException('Отдел не найден.', Response::HTTP_NOT_FOUND);
        }

        if ([] !== $this->repository->findChildren($department->getId())) {
            throw new AppException('Сначала удалите или перенесите подотделы.');
        }

        if ($this->profileRepository->countByDepartmentId($department->getId()) > 0) {
            throw new AppException('Отдел используется в профилях, удаление запрещено.');
        }

        $this->repository->remove($department);
    }
}
