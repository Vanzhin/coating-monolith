<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\CreateDriverProfile;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Waybills\Application\Service\AccessControl\DriverProfileAccessControl;
use App\Waybills\Application\Service\LocationCatalog;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Repository\DriverProfileRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CreateDriverProfileCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DriverProfileRepositoryInterface $profiles,
        private DriverProfileAccessControl $access,
        private LocationCatalog $locations,
    ) {
    }

    public function __invoke(CreateDriverProfileCommand $command): CreateDriverProfileCommandResult
    {
        $route = $this->locations->synchronizeSettings($command->routeSettings);
        $profile = new DriverProfile(
            Uuid::v7(), $this->access->currentUserId(), $command->driverDetails,
            $route->base, ...$route->destinations(),
        );
        $this->profiles->add($profile);

        return new CreateDriverProfileCommandResult($profile->getId());
    }
}
