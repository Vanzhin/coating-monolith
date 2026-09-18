<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\DeleteProject;

use App\Reports\Application\Service\AccessControl\ProjectAccessControl;
use App\Reports\Domain\Repository\ProjectRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class DeleteProjectCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProjectRepositoryInterface $repository,
        private ProjectAccessControl $access,
    ) {
    }

    public function __invoke(DeleteProjectCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $project = $this->repository->findOneById($command->id);
        if (null === $project) {
            throw new AppException('Проект не найден.', Response::HTTP_NOT_FOUND);
        }

        $this->repository->remove($project);
    }
}
