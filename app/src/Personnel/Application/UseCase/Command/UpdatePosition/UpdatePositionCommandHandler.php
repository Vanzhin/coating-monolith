<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\UpdatePosition;

use App\Personnel\Application\Service\AccessControl\PersonnelAccessControl;
use App\Personnel\Domain\Repository\PositionRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class UpdatePositionCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private PositionRepositoryInterface $repository,
        private PersonnelAccessControl $access,
    ) {
    }

    public function __invoke(UpdatePositionCommand $command): UpdatePositionCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $position = $this->repository->findOneById($command->id);
        if (null === $position) {
            throw new AppException('Должность не найдена.', Response::HTTP_NOT_FOUND);
        }

        // rename сам проверяет уникальность/длину через spec.
        $position->rename($command->title);
        $this->repository->add($position);

        return new UpdatePositionCommandResult($position->getId(), $position->getTitle());
    }
}
