<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\UpdateManufacturer;

use App\Coatings\Infrastructure\Repository\ManufacturerRepository;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class UpdateManufacturerCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ManufacturerRepository $manufacturerRepository
    )
    {
    }

    public function __invoke(UpdateManufacturerCommand $command): UpdateManufacturerCommandResult
    {
        $manufacturer = $this->manufacturerRepository->findOneById($command->manufacturerId);
        $manufacturer->setTitle($command->manufacturerDTO->title);
        $manufacturer->setDescription($command->manufacturerDTO->description);
        $this->manufacturerRepository->add($manufacturer);

        return new UpdateManufacturerCommandResult();
    }
}
