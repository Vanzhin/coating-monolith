<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateManufacturer;

use App\Coatings\Application\Service\AccessControl\CoatingAccessControl;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;

readonly class UpdateManufacturerCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ManufacturerRepositoryInterface $manufacturerRepository,
        private CoatingAccessControl $access
    ) {
    }

    public function __invoke(UpdateManufacturerCommand $command): UpdateManufacturerCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $manufacturer = $this->manufacturerRepository->findOneById($command->manufacturerId);
        $manufacturer->setTitle($command->manufacturerDTO->title);
        $manufacturer->setDescription($command->manufacturerDTO->description);
        $this->manufacturerRepository->add($manufacturer);

        return new UpdateManufacturerCommandResult();
    }
}
