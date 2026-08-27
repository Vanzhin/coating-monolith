<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\RemoveManufacturer;

use App\Coatings\Application\Service\AccessControl\CoatingAccessControl;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;

readonly class RemoveManufacturerCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ManufacturerRepositoryInterface $manufacturerRepository,
        private CoatingAccessControl $access,
    ) {
    }

    public function __invoke(RemoveManufacturerCommand $command): RemoveManufacturerCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $manufacturer = $this->manufacturerRepository->findOneById($command->id);
        $this->manufacturerRepository->remove($manufacturer);

        return new RemoveManufacturerCommandResult();
    }
}
