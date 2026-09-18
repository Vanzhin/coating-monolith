<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\UpdateProject;

use App\Reports\Application\Service\AccessControl\ProjectAccessControl;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Reports\Domain\Repository\ProjectRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class UpdateProjectCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProjectRepositoryInterface $repository,
        private CounterpartyRepositoryInterface $counterparties,
        private ProjectAccessControl $access,
    ) {
    }

    public function __invoke(UpdateProjectCommand $command): UpdateProjectCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $project = $this->repository->findOneById($command->id);
        if (null === $project) {
            throw new AppException('Проект не найден.', Response::HTTP_NOT_FOUND);
        }

        $counterparty = $this->counterparties->findOneById($command->counterpartyId);
        if (null === $counterparty) {
            throw new AppException('Контрагент не найден.', Response::HTTP_NOT_FOUND);
        }

        $project->setTitle($command->title);
        $project->setDescription($command->description);
        $project->setCounterparty($counterparty);
        $this->repository->add($project);

        return new UpdateProjectCommandResult($project->getId(), $project->getTitle());
    }
}
