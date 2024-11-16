<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\RemoveManufacturer;

use App\Coatings\Infrastructure\Repository\ManufacturerRepository;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class RemoveManufacturerCommandHandler implements CommandHandlerInterface
{
    public function __construct(private ManufacturerRepository $manufacturerRepository)
    {
    }

    public function __invoke(RemoveManufacturerCommand $command): RemoveManufacturerCommandResult
    {
        $manufacturer = $this->manufacturerRepository->findOneById($command->id);
        $this->manufacturerRepository->remove($manufacturer);

        return new RemoveManufacturerCommandResult();
    }
}
