<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\DeleteDepartment;

use App\Personnel\Application\Service\AccessControl\PersonnelAccessControl;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Удаление узла БЕЗ guard'а «используется в профилях» — профилей на этом этапе (T6) ещё нет,
 * guard добавит T7, когда появится Personnel/Profile.
 *
 * Целостность самого дерева (узел с детьми нельзя удалить, пока их не перенесли/удалили) —
 * оставляем здесь: это внутридеревесный инвариант, не про использование в других агрегатах.
 */
final readonly class DeleteDepartmentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DepartmentRepositoryInterface $repository,
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

        $this->repository->remove($department);
    }
}
