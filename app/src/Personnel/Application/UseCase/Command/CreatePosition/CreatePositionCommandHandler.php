<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\CreatePosition;

use App\Personnel\Application\Service\AccessControl\PersonnelAccessControl;
use App\Personnel\Domain\Aggregate\Position\Position;
use App\Personnel\Domain\Aggregate\Position\Specification\PositionSpecification;
use App\Personnel\Domain\Repository\PositionRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\ForbiddenException;

final readonly class CreatePositionCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private PositionRepositoryInterface $repository,
        private PositionSpecification $specification,
        private PersonnelAccessControl $access,
    ) {
    }

    public function __invoke(CreatePositionCommand $command): CreatePositionCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        // Уникальность и валидность title проверяет сам агрегат в сеттере (через spec).
        $position = new Position(UuidService::generate(), $command->title, $this->specification);
        $this->repository->add($position);

        return new CreatePositionCommandResult($position->getId(), $position->getTitle());
    }
}
