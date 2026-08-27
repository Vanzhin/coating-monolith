<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateManufacturer;

use App\Coatings\Application\Service\AccessControl\CoatingAccessControl;
use App\Coatings\Domain\Factory\ManufacturerFactory;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;

readonly class CreateManufacturerCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ManufacturerFactory $manufacturerFactory,
        private ManufacturerRepositoryInterface $manufacturerRepository,
        private CoatingAccessControl $access
    ) {
    }

    public function __invoke(CreateManufacturerCommand $command): CreateManufacturerCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $manufacturer = $this->manufacturerFactory->create($command->title, $command->description);
        $this->manufacturerRepository->add($manufacturer);

        return new CreateManufacturerCommandResult(
            $manufacturer->getId()
        );
    }
}
