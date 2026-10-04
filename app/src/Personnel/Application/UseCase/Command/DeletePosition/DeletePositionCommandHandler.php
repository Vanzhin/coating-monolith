<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\DeletePosition;

use App\Personnel\Application\Service\AccessControl\PersonnelAccessControl;
use App\Personnel\Domain\Repository\PositionRepositoryInterface;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class DeletePositionCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private PositionRepositoryInterface $repository,
        private ProfileRepositoryInterface $profileRepository,
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

        if ($this->profileRepository->countByPositionId($position->getId()) > 0) {
            throw new AppException('Должность используется в профилях, удаление запрещено.');
        }

        $this->repository->remove($position);
    }
}
