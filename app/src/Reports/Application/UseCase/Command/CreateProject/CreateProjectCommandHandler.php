<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\CreateProject;

use App\Reports\Application\Service\AccessControl\ProjectAccessControl;
use App\Reports\Domain\Aggregate\Project\Project;
use App\Reports\Domain\Aggregate\Project\Specification\ProjectSpecification;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Reports\Domain\Repository\ProjectRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class CreateProjectCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProjectRepositoryInterface $repository,
        private CounterpartyRepositoryInterface $counterparties,
        private ProjectSpecification $specification,
        private ProjectAccessControl $access,
    ) {
    }

    public function __invoke(CreateProjectCommand $command): CreateProjectCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $counterparty = $this->counterparties->findOneById($command->counterpartyId);
        if (null === $counterparty) {
            throw new AppException('Контрагент не найден.', Response::HTTP_NOT_FOUND);
        }

        $project = new Project(UuidService::generate(), $command->title, $this->specification, $counterparty, $command->description);
        $this->repository->add($project);

        return new CreateProjectCommandResult($project->getId(), $project->getTitle());
    }
}
