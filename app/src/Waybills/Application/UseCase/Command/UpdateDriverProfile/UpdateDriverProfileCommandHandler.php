<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\UpdateDriverProfile;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\Service\AccessControl\DriverProfileAccessControl;
use App\Waybills\Application\Service\LocationCatalog;
use App\Waybills\Domain\Repository\DriverProfileRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;

final readonly class UpdateDriverProfileCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DriverProfileRepositoryInterface $profiles,
        private DriverProfileAccessControl $access,
        private LocationCatalog $locations,
    ) {
    }

    public function __invoke(UpdateDriverProfileCommand $command): void
    {
        $profile = $this->profiles->findOneById((string) $command->id);
        if (null === $profile) {
            throw new AppException('Карточка водителя не найдена.', Response::HTTP_NOT_FOUND);
        }
        if (!$this->access->canEdit($profile)) {
            throw new ForbiddenException();
        }
        $route = $this->locations->synchronizeSettings($command->routeSettings);
        $profile->changeDriverDetails($command->driverDetails);
        $profile->replaceDestinations();
        $profile->changeBase($route->base);
        $profile->replaceDestinations(...$route->destinations());
        $this->profiles->add($profile);
    }
}
