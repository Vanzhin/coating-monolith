<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\CreateManufacturer;

use App\Coatings\Domain\Factory\ManufacturerFactory;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class CreateManufacturerCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ManufacturerFactory             $manufacturerFactory,
        private ManufacturerRepositoryInterface $manufacturerRepository
    )
    {
    }

    public function __invoke(CreateManufacturerCommand $command): CreateManufacturerCommandResult
    {
        $manufacturer = $this->manufacturerFactory->create($command->title, $command->description);
        $this->manufacturerRepository->add($manufacturer);

        return new CreateManufacturerCommandResult(
            $manufacturer->getId()
        );
    }
}
