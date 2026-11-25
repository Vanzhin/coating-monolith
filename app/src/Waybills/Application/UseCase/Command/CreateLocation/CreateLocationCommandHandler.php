<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\CreateLocation;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Waybills\Application\Service\LocationCatalog;

final readonly class CreateLocationCommandHandler implements CommandHandlerInterface
{
    public function __construct(private LocationCatalog $locations)
    {
    }

    public function __invoke(CreateLocationCommand $command): CreateLocationCommandResult
    {
        return new CreateLocationCommandResult($this->locations->resolveExact($command->location));
    }
}
