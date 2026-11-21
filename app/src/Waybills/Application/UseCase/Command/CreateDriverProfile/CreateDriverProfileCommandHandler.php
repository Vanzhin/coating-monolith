<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\CreateDriverProfile;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Waybills\Application\Service\AccessControl\DriverProfileAccessControl;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Repository\DriverProfileRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CreateDriverProfileCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DriverProfileRepositoryInterface $profiles,
        private DriverProfileAccessControl $access,
    ) {
    }

    public function __invoke(CreateDriverProfileCommand $command): CreateDriverProfileCommandResult
    {
        $profile = new DriverProfile(
            Uuid::v7(), $this->access->currentUserId(), $command->driverDetails,
            $command->routeSettings->base, ...$command->routeSettings->destinations(),
        );
        $this->profiles->add($profile);

        return new CreateDriverProfileCommandResult($profile->getId());
    }
}
