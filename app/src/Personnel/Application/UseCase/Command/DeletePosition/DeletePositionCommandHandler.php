<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\DeletePosition;

use App\Personnel\Application\Service\AccessControl\PersonnelAccessControl;
use App\Personnel\Domain\Repository\PositionRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Простое удаление, БЕЗ guard'а «должность используется в профилях» — профилей на этом этапе
 * (T4) ещё нет, guard добавит T7, когда появится Personnel/Profile.
 */
final readonly class DeletePositionCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private PositionRepositoryInterface $repository,
        private PersonnelAccessControl $access,
    ) {
    }

    public function __invoke(DeletePositionCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $position = $this->repository->findOneById($command->id);
        if (null === $position) {
            throw new AppException('Должность не найдена.', Response::HTTP_NOT_FOUND);
        }

        $this->repository->remove($position);
    }
}
